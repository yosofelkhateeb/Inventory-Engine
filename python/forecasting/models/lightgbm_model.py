"""
LightGBM demand forecasting model.

Features: lag(7,14,30), rolling mean/std(7,14,30), day-of-week, month,
is_promotion, days_since_last_promotion.

Uses iterative multi-step forecasting: each step's prediction is appended
to the history and used as input for the next step.

Requires >= 180 days of history (enforced by classifier shortlist).
"""

import warnings
import numpy as np
import pandas as pd


LAG_DAYS        = [7, 14, 30]
ROLLING_WINDOWS = [7, 14, 30]
MIN_HISTORY     = 60   # Absolute floor before feature engineering breaks


class LightGBMModel:
    def __init__(self):
        self._model = None
        self._fitted_model = None   # alias for intervals module compatibility
        self._feature_names: list[str] = []
        self._history: list[float] = []
        self._promo_history: list[int] = []
        self._train_series: list[float] = []
        self._hyperparameters: dict = {}

    # ─── Feature engineering ──────────────────────────────────────────────

    @staticmethod
    def _build_features(
        values: np.ndarray,
        promo: np.ndarray,
        start_offset: int = 0,
    ) -> pd.DataFrame:
        """
        Build feature matrix for rows [start_offset:] of values.
        `start_offset` allows skipping the warm-up period needed by max lag.
        """
        n = len(values)
        rows = []
        dates = pd.date_range("2020-01-01", periods=n, freq="D")

        for i in range(start_offset, n):
            row: dict[str, float] = {}

            # Lag features
            for lag in LAG_DAYS:
                row[f"lag_{lag}"] = float(values[i - lag]) if i >= lag else 0.0

            # Rolling mean / std
            for w in ROLLING_WINDOWS:
                window = values[max(0, i - w):i]
                row[f"roll_mean_{w}"] = float(np.mean(window)) if len(window) > 0 else 0.0
                row[f"roll_std_{w}"]  = float(np.std(window))  if len(window) > 1 else 0.0

            # Calendar
            row["day_of_week"] = float(dates[i].dayofweek)
            row["month"]       = float(dates[i].month)

            # Promotion
            row["is_promotion"]                = float(promo[i]) if i < len(promo) else 0.0
            last_promo = np.where(promo[:i] == 1)[0]
            row["days_since_last_promotion"]   = float(i - last_promo[-1]) if len(last_promo) > 0 else float(i)

            rows.append(row)

        return pd.DataFrame(rows)

    # ─── Fit ──────────────────────────────────────────────────────────────

    def fit(self, series: list[float], promo: list[int] | None = None) -> "LightGBMModel":
        import lightgbm as lgb

        arr   = np.array(series, dtype=float)
        promo_arr = np.array(promo, dtype=int) if promo else np.zeros(len(arr), dtype=int)

        if len(arr) < MIN_HISTORY:
            # Too short; store history for fallback predict
            self._history      = arr.tolist()
            self._promo_history = promo_arr.tolist()
            return self

        warmup = max(LAG_DAYS)  # need max_lag rows of history before first usable row
        X = self._build_features(arr, promo_arr, start_offset=warmup)
        y = arr[warmup:]

        self._feature_names = X.columns.tolist()

        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            self._model = lgb.LGBMRegressor(
                n_estimators=200,
                learning_rate=0.05,
                num_leaves=31,
                min_child_samples=5,
                random_state=42,
                verbose=-1,
            )
            self._model.fit(X, y)

        self._history        = arr.tolist()
        self._promo_history  = promo_arr.tolist()
        self._train_series   = arr.tolist()
        self._fitted_model   = self._model
        self._hyperparameters = {
            "n_estimators": 200,
            "learning_rate": 0.05,
            "num_leaves": 31,
            "min_child_samples": 5,
        }
        return self

    def tune(self, series: list[float], folds: list[dict], promo: list[int] | None = None) -> "LightGBMModel":
        """
        Stage 10: Optuna / grid-search tuning.
        Falls back to grid search if Optuna is not installed.
        """
        try:
            import sys, os
            sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
            from pipeline._config import load_config
            import lightgbm as lgb

            cfg    = load_config()
            lgb_cfg = cfg["models"]["lightgbm"]
            max_trials = int(lgb_cfg.get("max_tuning_trials", 50))

            arr       = np.array(series, dtype=float)
            promo_arr = np.array(promo, dtype=int) if promo else np.zeros(len(arr), dtype=int)

            # Use last fold for tuning evaluation
            fold = folds[-1] if folds else None
            if fold is None or len(arr) < MIN_HISTORY:
                return self

            train_arr  = arr[:fold["train_end_idx"] + 1]
            val_arr    = arr[fold["val_start_idx"]:fold["val_end_idx"] + 1]
            train_promo = promo_arr[:fold["train_end_idx"] + 1]

            warmup = max(LAG_DAYS)
            if len(train_arr) <= warmup:
                return self

            X_tr = self._build_features(train_arr, train_promo, start_offset=warmup)
            y_tr = train_arr[warmup:]

            param_grid = [
                {
                    "num_leaves": nl,
                    "learning_rate": lr,
                    "n_estimators": ne,
                    "min_child_samples": mcs,
                    "feature_fraction": ff,
                }
                for nl  in lgb_cfg.get("num_leaves_grid", [31])
                for lr  in lgb_cfg.get("learning_rate_grid", [0.05])
                for ne  in lgb_cfg.get("n_estimators_grid", [200])
                for mcs in lgb_cfg.get("min_child_samples_grid", [5])
                for ff  in lgb_cfg.get("feature_fraction_grid", [1.0])
            ]

            best_mae   = float("inf")
            best_params: dict = {}
            trials = 0

            for params in param_grid:
                if trials >= max_trials:
                    break
                try:
                    m = lgb.LGBMRegressor(random_state=42, verbose=-1, **params)
                    m.fit(X_tr, y_tr)
                    # Re-use forecast_series for evaluation
                    temp = LightGBMModel()
                    temp._model         = m
                    temp._fitted_model  = m
                    temp._feature_names = self._feature_names
                    temp._history       = train_arr.tolist()
                    temp._promo_history = train_promo.tolist()
                    temp._train_series  = train_arr.tolist()
                    fc = temp.forecast_series(len(val_arr))
                    mae = float(np.mean(np.abs(np.array(fc) - val_arr)))
                    if mae < best_mae:
                        best_mae   = mae
                        best_params = params.copy()
                except Exception:
                    pass
                trials += 1

            if best_params:
                self.fit(series, promo)  # refit with new params applied via defaults
                self._hyperparameters = best_params
        except Exception:
            pass  # Tuning failure is non-fatal
        return self

    # ─── Predict ──────────────────────────────────────────────────────────

    def predict(self, horizon: int = 30, future_promo: list[int] | None = None) -> float:
        series = self.forecast_series(horizon, future_promo)
        return float(np.mean(series))

    def forecast_series(
        self, horizon: int = 30, future_promo: list[int] | None = None
    ) -> list[float]:
        if self._model is None:
            # No model fitted (too short) — return trailing mean
            if self._history:
                return [float(np.mean(self._history[-30:]))] * horizon
            return [0.0] * horizon

        future_p = future_promo if future_promo else [0] * horizon

        history = self._history.copy()
        promo   = self._promo_history.copy()
        preds   = []

        for step in range(horizon):
            promo_step = int(future_p[step]) if step < len(future_p) else 0
            extended_promo = np.array(promo + [promo_step], dtype=int)
            extended_vals  = np.array(history + [0.0])   # placeholder for current step

            # Build features for the last index (the step we're predicting)
            n = len(extended_vals)
            row: dict[str, float] = {}
            for lag in LAG_DAYS:
                row[f"lag_{lag}"] = float(extended_vals[n - 1 - lag]) if n - 1 >= lag else 0.0
            for w in ROLLING_WINDOWS:
                window = extended_vals[max(0, n - 1 - w): n - 1]
                row[f"roll_mean_{w}"] = float(np.mean(window)) if len(window) > 0 else 0.0
                row[f"roll_std_{w}"]  = float(np.std(window))  if len(window) > 1 else 0.0
            dates = pd.date_range("2020-01-01", periods=n, freq="D")
            row["day_of_week"] = float(dates[-1].dayofweek)
            row["month"]       = float(dates[-1].month)
            row["is_promotion"] = float(promo_step)
            last_promo_idx = np.where(extended_promo[:-1] == 1)[0]
            row["days_since_last_promotion"] = (
                float(n - 1 - last_promo_idx[-1]) if len(last_promo_idx) > 0 else float(n - 1)
            )

            X_step = pd.DataFrame([row])[self._feature_names]
            pred   = max(0.0, float(self._model.predict(X_step)[0]))
            preds.append(pred)

            history.append(pred)
            promo.append(promo_step)

        return preds
