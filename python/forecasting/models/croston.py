"""
Croston's Method for intermittent demand forecasting.

Standard statistical models (ETS, ARIMA) behave poorly when a SKU has
frequent zero-sales days. Croston separates the demand size from the
inter-demand interval and smooths each independently.

Uses statsmodels if available; falls back to a manual implementation.
"""

import numpy as np


class Croston:
    """
    Croston's method: smooths demand sizes and inter-demand intervals
    separately, then combines them for a per-day demand rate estimate.
    """

    def __init__(self, alpha: float = 0.1):
        """
        alpha: smoothing factor (0 < alpha < 1).
        Lower values = slower adaptation; typically 0.05–0.20 for sparse demand.
        """
        self.alpha = alpha
        self._demand_rate: float | None = None
        self._train_series: list[float] = []

    def fit(self, series: list[float]) -> "Croston":
        arr = np.array(series, dtype=float)

        non_zero_idx = np.where(arr > 0)[0]

        if len(non_zero_idx) == 0:
            # No demand observed — use a tiny placeholder
            self._demand_rate = 0.0
            return self

        # Extract demand sizes at non-zero events
        demand_sizes = arr[non_zero_idx]

        # Compute inter-arrival intervals (gap between consecutive demand events)
        if len(non_zero_idx) == 1:
            intervals = np.array([non_zero_idx[0] + 1], dtype=float)
        else:
            intervals = np.diff(non_zero_idx, prepend=0).astype(float)
            intervals[0] = non_zero_idx[0] + 1  # first interval from start of series

        # Exponential smoothing on sizes and intervals
        z = demand_sizes[0]    # smoothed demand size
        p = intervals[0]       # smoothed inter-arrival interval

        for i in range(1, len(demand_sizes)):
            z = self.alpha * demand_sizes[i] + (1 - self.alpha) * z
            p = self.alpha * intervals[i] + (1 - self.alpha) * p

        # Croston demand rate = smoothed size / smoothed interval
        self._demand_rate = z / p if p > 0 else 0.0
        self._train_series = series if isinstance(series, list) else list(series)
        return self

    def predict(self, horizon: int = 30) -> float:
        """Return the estimated average daily demand rate."""
        if self._demand_rate is None:
            raise RuntimeError("Call fit() before predict()")
        return float(self._demand_rate)

    def forecast_series(self, horizon: int = 30) -> list[float]:
        """Return a flat demand rate repeated over the horizon."""
        rate = self.predict(horizon)
        return [rate] * horizon
