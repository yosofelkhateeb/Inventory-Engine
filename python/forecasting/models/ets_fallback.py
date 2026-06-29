"""
ETS Fallback — Simple Exponential Smoothing via statsmodels.

Always available as a baseline competitor. Assigned when:
- History is below min_history_days for the SKU's category.
- All other models in the shortlist fail or produce worse MAE.
"""

import numpy as np
from statsmodels.tsa.holtwinters import SimpleExpSmoothing


class ETSFallback:
    """Simple exponential smoothing baseline model."""

    def __init__(self):
        self._model = None
        self._fitted = None
        self._train_series: list[float] = []

    def fit(self, series: list[float]) -> "ETSFallback":
        """Fit on a list of daily demand values."""
        arr = np.array(series, dtype=float)
        # SES requires at least 2 observations
        if len(arr) < 2:
            arr = np.append(arr, arr[-1] if len(arr) else 0.0)
        self._model = SimpleExpSmoothing(arr, initialization_method="estimated")
        self._fitted = self._model.fit(optimized=True)
        self._train_series = series if isinstance(series, list) else list(series)
        return self

    def predict(self, horizon: int = 30) -> float:
        """Return the average daily demand forecast over the horizon."""
        if self._fitted is None:
            raise RuntimeError("Call fit() before predict()")
        forecast = self._fitted.forecast(horizon)
        # SES predicts a flat line; return the mean (== any single step value)
        return float(np.mean(forecast))

    def forecast_series(self, horizon: int = 30) -> list[float]:
        """Return the per-day forecast list for evaluation."""
        if self._fitted is None:
            raise RuntimeError("Call fit() before predict()")
        return self._fitted.forecast(horizon).tolist()
