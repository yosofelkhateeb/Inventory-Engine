"""
Prophet model wrapper — optional dependency.

If the `prophet` package is not installed this module exports
NotAvailable = True so callers can skip gracefully.
"""

import sys
import warnings

try:
    from prophet import Prophet
    NotAvailable = False
except ImportError:
    NotAvailable = True


class ProphetModel:
    """
    Meta Prophet wrapper.

    Accepts Saudi/Malaysian national holidays from the regional_holidays
    table and client promotions as additional regressors.
    """

    def __init__(self):
        if NotAvailable:
            raise ImportError("prophet is not installed. Run: pip install prophet")
        self._model = None
        self._fitted = None
        self._future_regressors: list[str] = []

    def fit(
        self,
        series: list[float],
        dates: list[str] | None = None,
        holidays_df=None,          # pd.DataFrame with columns: ds, holiday
        promotion_series: list[int] | None = None,
    ) -> "ProphetModel":
        import pandas as pd

        n = len(series)
        if dates:
            date_index = pd.to_datetime(dates)
        else:
            date_index = pd.date_range("2020-01-01", periods=n, freq="D")

        df = pd.DataFrame({"ds": date_index, "y": series})

        regressors = []
        if promotion_series is not None:
            df["is_promotion"] = promotion_series
            regressors.append("is_promotion")

        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            m = Prophet(
                yearly_seasonality=True,
                weekly_seasonality=True,
                daily_seasonality=False,
                holidays=holidays_df,
                interval_width=0.80,
            )
            for reg in regressors:
                m.add_regressor(reg)

            m.fit(df)

        self._model            = m
        self._future_regressors = regressors
        return self

    def predict(self, horizon: int = 30, future_promo: list[int] | None = None) -> float:
        import numpy as np
        series = self.forecast_series(horizon, future_promo)
        return float(np.mean(series))

    def forecast_series(
        self, horizon: int = 30, future_promo: list[int] | None = None
    ) -> list[float]:
        import numpy as np

        if self._model is None:
            raise RuntimeError("Call fit() before predict()")

        future = self._model.make_future_dataframe(periods=horizon, freq="D")
        future = future.tail(horizon).reset_index(drop=True)

        if "is_promotion" in self._future_regressors:
            if future_promo is not None:
                future["is_promotion"] = future_promo[:horizon]
            else:
                future["is_promotion"] = 0

        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            forecast = self._model.predict(future)

        return np.maximum(forecast["yhat"].values, 0).tolist()
