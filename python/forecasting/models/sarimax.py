"""
SARIMAX — Seasonal ARIMA with exogenous promotion indicator.

Order selected by pmdarima.auto_arima. The promotion column is the only
model in the system that ingests the promotional calendar directly as a
regressor (other models use post-forecast uplift adjustment instead).

Falls back to plain ARIMA if no promotion data is supplied or if the
seasonal fit fails.
"""

import warnings
import numpy as np
import pandas as pd


class SARIMAX:
    def __init__(self, log_transform: bool = False):
        self._fitted = None
        self._has_exog = False
        self._future_exog: np.ndarray | None = None
        # When True, fit on log1p(series) and expm1 outputs. Stabilises variance
        # for multiplicative effects (promo spikes, heteroscedastic demand).
        # Intervals.py reads _fitted.conf_int() directly, so it inspects this
        # flag and applies the same expm1 to the bounds.
        self._log_transform = bool(log_transform)

    def fit(
        self,
        series: list[float],
        exog: list[float] | None = None,   # 1 = promotion active, 0 = not
    ) -> "SARIMAX":
        import pmdarima as pm
        from statsmodels.tsa.statespace.sarimax import SARIMAX as SM_SARIMAX

        arr = np.array(series, dtype=float)
        if self._log_transform:
            arr = np.log1p(arr)
        exog_arr = np.array(exog, dtype=float).reshape(-1, 1) if exog else None
        self._has_exog = exog_arr is not None

        with warnings.catch_warnings():
            warnings.simplefilter("ignore")

            try:
                # Auto-select ARIMA order; try seasonal first
                auto = pm.auto_arima(
                    arr,
                    exogenous=exog_arr,
                    seasonal=True,
                    m=7,
                    stepwise=True,
                    suppress_warnings=True,
                    error_action="ignore",
                    max_p=3, max_q=3, max_P=2, max_Q=2, max_d=2, max_D=1,
                    information_criterion="aic",
                )
                order = auto.order
                seasonal_order = auto.seasonal_order

                model = SM_SARIMAX(
                    arr,
                    exog=exog_arr,
                    order=order,
                    seasonal_order=seasonal_order,
                    enforce_stationarity=False,
                    enforce_invertibility=False,
                )
                self._fitted = model.fit(disp=False)

            except Exception:
                # Fall back to ARIMA(1,1,1) without seasonal
                try:
                    from statsmodels.tsa.arima.model import ARIMA
                    model = ARIMA(arr, exog=exog_arr, order=(1, 1, 1))
                    self._fitted = model.fit()
                except Exception:
                    # Last resort — zero-forecast (picked up by evaluator as worst)
                    self._fitted = None

        return self

    def predict(self, horizon: int = 30, future_exog: list[float] | None = None) -> float:
        series = self.forecast_series(horizon, future_exog)
        return float(np.mean(series)) if series else 0.0

    def forecast_series(
        self, horizon: int = 30, future_exog: list[float] | None = None
    ) -> list[float]:
        if self._fitted is None:
            return [0.0] * horizon

        exog_f = None
        if self._has_exog:
            if future_exog is not None:
                exog_f = np.array(future_exog, dtype=float).reshape(-1, 1)
            else:
                exog_f = np.zeros((horizon, 1))

        try:
            with warnings.catch_warnings():
                warnings.simplefilter("ignore")
                forecast = self._fitted.forecast(steps=horizon, exog=exog_f)
            forecast = np.asarray(forecast, dtype=float)
            if self._log_transform:
                forecast = np.expm1(forecast)
            return np.maximum(forecast, 0).tolist()
        except Exception:
            return [0.0] * horizon
