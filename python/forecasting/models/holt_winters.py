"""
Holt-Winters Triple Exponential Smoothing.

Additive trend; seasonality type (additive vs multiplicative) selected by AIC.
Seasonal period = 7 (weekly). Requires at least 2 full seasonal periods (14 days)
for seasonal fitting; falls back to trend-only if too short.
"""

import warnings
import numpy as np
from statsmodels.tsa.holtwinters import ExponentialSmoothing


SEASONAL_PERIOD = 7


class HoltWinters:
    def __init__(self):
        self._fitted = None
        self._seasonal_type = None
        self._train_series: list[float] = []
        self._hyperparameters: dict = {}

    def fit(self, series: list[float]) -> "HoltWinters":
        arr = np.array(series, dtype=float)
        # Replace exact zeros with a tiny positive value so multiplicative
        # seasonality doesn't blow up.
        arr = np.where(arr == 0, 0.01, arr)

        use_seasonal = len(arr) >= SEASONAL_PERIOD * 2

        best_aic = np.inf
        best_fit = None
        best_type = None

        seasonal_types = ["add", "mul"] if use_seasonal else [None]

        for stype in seasonal_types:
            try:
                with warnings.catch_warnings():
                    warnings.simplefilter("ignore")
                    m = ExponentialSmoothing(
                        arr,
                        trend="add",
                        seasonal=stype,
                        seasonal_periods=SEASONAL_PERIOD if stype else None,
                        initialization_method="estimated",
                    )
                    fit = m.fit(optimized=True)
                    if fit.aic < best_aic:
                        best_aic = fit.aic
                        best_fit = fit
                        best_type = stype
            except Exception:
                continue

        # If all seasonal fits failed, try simple trend-only
        if best_fit is None:
            with warnings.catch_warnings():
                warnings.simplefilter("ignore")
                m = ExponentialSmoothing(arr, trend="add", initialization_method="estimated")
                best_fit = m.fit(optimized=True)
                best_type = None

        self._fitted = best_fit
        self._seasonal_type = best_type
        self._train_series = series if isinstance(series, list) else list(series)
        self._hyperparameters = {
            "seasonal_type": best_type,
            "seasonal_period": SEASONAL_PERIOD,
        }
        return self

    def tune(self, series: list[float], folds: list[dict]) -> "HoltWinters":
        """
        Stage 10: Grid search over smoothing parameters.
        Re-fits if improvement > threshold from config.
        """
        try:
            import sys, os
            sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
            from pipeline._config import load_config
            cfg = load_config()
            hw_cfg = cfg["models"]["holt_winters"]
            threshold = float(hw_cfg.get("tuning_improvement_threshold_pct", 5.0)) / 100

            alpha_grid = hw_cfg.get("smoothing_level_grid", [0.3])
            beta_grid  = hw_cfg.get("smoothing_trend_grid",  [0.1])
            gamma_grid = hw_cfg.get("smoothing_seasonal_grid", [0.3]) if self._seasonal_type else [None]

            arr = np.array(series, dtype=float)
            arr = np.where(arr == 0, 0.01, arr)

            best_mae = float("inf")
            best_params: dict = {}

            # Quick CV on last fold only (tuning speed)
            fold = folds[-1] if folds else None
            if fold is None:
                return self

            train = arr[:fold["train_end_idx"] + 1]
            val   = arr[fold["val_start_idx"]:fold["val_end_idx"] + 1]

            for alpha in alpha_grid:
                for beta in beta_grid:
                    for gamma in (gamma_grid if gamma_grid[0] is not None else [None]):
                        try:
                            with warnings.catch_warnings():
                                warnings.simplefilter("ignore")
                                m = ExponentialSmoothing(
                                    train,
                                    trend="add",
                                    seasonal=self._seasonal_type,
                                    seasonal_periods=SEASONAL_PERIOD if self._seasonal_type else None,
                                    initialization_method="estimated",
                                )
                                params = {"smoothing_level": alpha, "smoothing_trend": beta}
                                if gamma is not None:
                                    params["smoothing_seasonal"] = gamma
                                fit = m.fit(**params, optimized=False)
                                fc  = np.maximum(fit.forecast(len(val)), 0)
                                mae = float(np.mean(np.abs(fc - val)))
                                if mae < best_mae:
                                    best_mae   = mae
                                    best_params = params.copy()
                        except Exception:
                            continue

            # Only apply tuned params if improvement > threshold
            if best_params:
                default_fc = np.maximum(self._fitted.forecast(len(val)), 0)
                default_mae = float(np.mean(np.abs(default_fc - val)))
                if default_mae == 0 or (default_mae - best_mae) / default_mae > threshold:
                    m = ExponentialSmoothing(
                        arr,
                        trend="add",
                        seasonal=self._seasonal_type,
                        seasonal_periods=SEASONAL_PERIOD if self._seasonal_type else None,
                        initialization_method="estimated",
                    )
                    with warnings.catch_warnings():
                        warnings.simplefilter("ignore")
                        self._fitted = m.fit(**best_params, optimized=False)
                    self._hyperparameters.update(best_params)
        except Exception:
            pass  # Tuning failure is non-fatal — keep the default fit
        return self

    def predict(self, horizon: int = 30) -> float:
        if self._fitted is None:
            raise RuntimeError("Call fit() before predict()")
        forecast = self._fitted.forecast(horizon)
        return float(np.mean(np.maximum(forecast, 0)))

    def forecast_series(self, horizon: int = 30) -> list[float]:
        if self._fitted is None:
            raise RuntimeError("Call fit() before predict()")
        return np.maximum(self._fitted.forecast(horizon), 0).tolist()
