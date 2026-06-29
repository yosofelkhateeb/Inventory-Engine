"""
Stage 11: Prediction Intervals

Generates prediction intervals by model type:
- Statistical models (Holt-Winters, SARIMAX): native statsmodels intervals
- LightGBM: residual bootstrap
- ETS fallback + Croston: residual std proxy

Validates empirical coverage on held-out set.
Applies correction factor if coverage < 80%.
"""
import logging

import numpy as np

from pipeline._config import load_config

logger = logging.getLogger(__name__)


def compute(
    model_name: str,
    model_obj,
    train_series: list[float],
    holdout_actual: np.ndarray,
    horizon: int,
    exog_train: list[float] | None = None,
    exog_future: list[float] | None = None,
) -> dict:
    """
    Generate prediction intervals for `horizon` steps and validate coverage.

    Returns:
        {
            interval_lower            : float  — mean lower bound
            interval_upper            : float  — mean upper bound
            interval_confidence       : float
            interval_empirical_coverage: float
            correction_applied        : bool
        }
    """
    cfg        = load_config()
    conf       = float(cfg["intervals"]["confidence_level"])
    n_boot     = int(cfg["intervals"]["bootstrap_samples"])
    min_cov    = float(cfg["intervals"]["min_acceptable_coverage"])
    corr_f     = float(cfg["intervals"]["correction_factor"])

    # ── Generate intervals ────────────────────────────────────────────────────
    lower, upper = _generate_intervals(
        model_name, model_obj, train_series, horizon, conf, n_boot, exog_train, exog_future
    )

    # ── Validate empirical coverage on held-out set ────────────────────────────
    empirical_coverage = _compute_coverage(holdout_actual, lower, upper, horizon)

    correction_applied = False
    if empirical_coverage < min_cov and len(lower) > 0 and len(upper) > 0:
        half_width = (np.array(upper) - np.array(lower)) / 2
        midpoints  = (np.array(upper) + np.array(lower)) / 2
        lower = list(np.maximum(0.0, midpoints - half_width * corr_f))
        upper = list(midpoints + half_width * corr_f)
        correction_applied = True
        # Recompute coverage after correction
        empirical_coverage = _compute_coverage(holdout_actual, lower, upper, horizon)
        logger.info(
            f"Intervals corrected for {model_name}: coverage after = {empirical_coverage:.2f}"
        )

    # Summarise to scalar mean values for registry storage
    lower_arr = np.maximum(0.0, np.array(lower, dtype=float))
    upper_arr = np.maximum(0.0, np.array(upper, dtype=float))

    return {
        "interval_lower":             round(float(np.mean(lower_arr)), 4) if len(lower_arr) > 0 else 0.0,
        "interval_upper":             round(float(np.mean(upper_arr)), 4) if len(upper_arr) > 0 else 0.0,
        "interval_confidence":        conf,
        "interval_empirical_coverage": round(float(empirical_coverage), 3),
        "correction_applied":          correction_applied,
        "lower_series":               [round(float(v), 4) for v in lower_arr],
        "upper_series":               [round(float(v), 4) for v in upper_arr],
    }


# ── Model-specific interval generation ────────────────────────────────────────

def _generate_intervals(
    model_name: str,
    model_obj,
    train_series: list[float],
    horizon: int,
    conf: float,
    n_boot: int,
    exog_train: list[float] | None,
    exog_future: list[float] | None,
) -> tuple[list[float], list[float]]:
    """Dispatch to the right interval method for this model type."""
    try:
        if model_name == "holt_winters":
            return _native_intervals_hw(model_obj, train_series, horizon, conf)
        elif model_name == "sarimax":
            return _native_intervals_sarimax(model_obj, train_series, horizon, conf, exog_future)
        elif model_name == "lightgbm":
            return _bootstrap_intervals(model_obj, train_series, horizon, n_boot, exog_train, exog_future)
        else:
            # ets_fallback, croston: residual std proxy
            return _residual_std_intervals(model_obj, train_series, horizon, conf)
    except Exception as exc:
        logger.warning(f"Interval generation failed for {model_name}: {exc}. Using empty intervals.")
        return [], []


def _ci_to_ndarray(ci) -> np.ndarray:
    # statsmodels' .conf_int() returns a DataFrame for pandas-fed models
    # (Holt-Winters) but a raw ndarray for numpy-fed ones (SARIMAX). Normalise
    # so downstream indexing (ci[:, 0]) works in both cases.
    if hasattr(ci, "values"):
        return np.asarray(ci.values)
    return np.asarray(ci)


def _native_intervals_hw(model_obj, train_series: list[float], horizon: int, conf: float) -> tuple[list[float], list[float]]:
    """Holt-Winters native intervals via predict() with alpha parameter."""
    try:
        fitted = getattr(model_obj, "_fitted", None)
        if fitted is None:
            raise ValueError("Model not fitted")
        alpha = 1 - conf
        pred = fitted.get_prediction(
            start=len(model_obj._train_series),
            end=len(model_obj._train_series) + horizon - 1,
        )
        ci = _ci_to_ndarray(pred.conf_int(alpha=alpha))
        lower = list(np.maximum(0.0, ci[:, 0]))
        upper = list(np.maximum(0.0, ci[:, 1]))
        return lower, upper
    except Exception as exc:
        logger.warning(f"holt_winters native intervals failed: {exc}. Falling back to residual-std proxy.")
        return _residual_std_intervals(model_obj, train_series or getattr(model_obj, "_train_series", []) or [], horizon, conf)


def _native_intervals_sarimax(model_obj, train_series: list[float], horizon: int, conf: float, exog_future) -> tuple[list[float], list[float]]:
    """SARIMAX native intervals."""
    try:
        fitted = getattr(model_obj, "_fitted", None)
        if fitted is None:
            raise ValueError("Model not fitted")
        alpha = 1 - conf
        # Match exog shape used at fit time: (horizon, 1) column vector.
        exog_arr = None
        if exog_future is not None:
            exog_arr = np.asarray(exog_future, dtype=float).reshape(-1, 1)
        fc_res = fitted.get_forecast(steps=horizon, exog=exog_arr)
        ci = _ci_to_ndarray(fc_res.conf_int(alpha=alpha))
        # If the SARIMAX object was fit on log1p-transformed data, the CI bounds
        # are also in log-space. Back-transform via expm1 so the registry stores
        # raw-scale bounds the rest of the system understands.
        if getattr(model_obj, "_log_transform", False):
            ci = np.expm1(ci)
        lower = list(np.maximum(0.0, ci[:, 0]))
        upper = list(np.maximum(0.0, ci[:, 1]))
        return lower, upper
    except Exception as exc:
        logger.warning(f"sarimax native intervals failed: {exc}. Falling back to residual-std proxy.")
        return _residual_std_intervals(model_obj, train_series, horizon, conf)


def _bootstrap_intervals(
    model_obj,
    train_series: list[float],
    horizon: int,
    n_boot: int,
    exog_train,
    exog_future,
) -> tuple[list[float], list[float]]:
    """Residual-based bootstrap intervals for LightGBM."""
    try:
        fitted = getattr(model_obj, "_fitted_model", None)
        if fitted is None or not train_series:
            raise ValueError("Model not fitted or no train data")

        # Get training residuals
        train_fc = model_obj.forecast_series(len(train_series), future_promo=exog_train)
        residuals = np.array(train_series) - np.array(train_fc[:len(train_series)])
        residuals = residuals[~np.isnan(residuals)]

        if len(residuals) < 2:
            raise ValueError("Not enough residuals for bootstrap")

        # Bootstrap
        point_fc = model_obj.forecast_series(horizon, future_promo=exog_future)
        samples   = np.random.choice(residuals, size=(n_boot, horizon), replace=True)
        boot_fc   = np.array(point_fc) + samples

        lower = list(np.maximum(0.0, np.percentile(boot_fc, 2.5, axis=0)))
        upper = list(np.maximum(0.0, np.percentile(boot_fc, 97.5, axis=0)))
        return lower, upper
    except Exception:
        return _residual_std_intervals(model_obj, train_series, horizon, 0.95)


def _residual_std_intervals(
    model_obj,
    train_series: list[float],
    horizon: int,
    conf: float,
) -> tuple[list[float], list[float]]:
    """Simple ±1.96σ proxy using training residuals."""
    try:
        if not train_series:
            return [], []
        train_fc  = model_obj.forecast_series(len(train_series))
        residuals = np.array(train_series) - np.array(train_fc[:len(train_series)])
        std_r     = float(np.std(residuals[~np.isnan(residuals)])) if len(residuals) > 1 else 1.0

        from scipy.stats import norm
        z = float(norm.ppf((1 + conf) / 2))

        fc = model_obj.forecast_series(horizon)
        lower = [max(0.0, v - z * std_r) for v in fc]
        upper = [max(0.0, v + z * std_r) for v in fc]
        return lower, upper
    except Exception:
        return [], []


def _compute_coverage(
    actual: np.ndarray,
    lower: list[float],
    upper: list[float],
    horizon: int,
) -> float:
    """Fraction of actuals that fall within [lower, upper]."""
    if len(actual) == 0 or not lower or not upper:
        return 0.0
    n   = min(len(actual), horizon, len(lower), len(upper))
    a   = np.array(actual[:n], dtype=float)
    lo  = np.array(lower[:n],  dtype=float)
    hi  = np.array(upper[:n],  dtype=float)
    in_interval = ((a >= lo) & (a <= hi)).sum()
    return float(in_interval / n)
