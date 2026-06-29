"""
Registry formatter.

Produces the output JSON consumed by RunForecastJob (PHP).
Full output schema per FORECASTING_ENGINE.md Stage 13.
"""
from datetime import datetime, timezone, timedelta


REVIEW_INTERVAL_DAYS = 30


def build_output(
    sku_id:          int,
    tenant_id:       int,
    model_name:      str,
    demand_rate:     float,
    metrics:         dict,         # {mae, rmse, bias, smape} — or {cv_mae, test_mae, ...}
    profile:         dict,         # from classifier
    reeval_trigger:  str,
    horizon:         int = 30,
    warnings:        list[str] | None = None,
    intervals:       dict | None = None,     # from pipeline/intervals.py
    selection:       dict | None = None,     # from models/selection.py
    hyperparameters: dict | None = None,     # model-specific tuning results
    transformation:  str = "none",
) -> dict:
    """
    Build the complete output dict ready for json.dumps().
    """
    now         = datetime.now(timezone.utc)
    next_review = now + timedelta(days=REVIEW_INTERVAL_DAYS)

    # Normalise metrics — support both CV-style and simple-style keys.
    # Default to None (not 0) so callers can distinguish "evaluated as zero
    # error" from "never evaluated" (e.g. classifier short-circuit on
    # insufficient history). The benchmark report and Reports UI display
    # null as "—" rather than "0%".
    mae   = metrics.get("test_mae",  metrics.get("mae",   None))
    rmse  = metrics.get("test_rmse", metrics.get("rmse",  None))
    bias  = metrics.get("test_bias", metrics.get("bias",  None))
    smape = metrics.get("test_smape",metrics.get("smape", None))

    def _round_or_none(v, ndigits):
        return None if v is None else round(float(v), ndigits)

    intervals = intervals or {}
    selection = selection or {}

    return {
        "sku_id":                     sku_id,
        "tenant_id":                  tenant_id,
        "model_name":                 model_name,
        "demand_rate":                round(float(demand_rate), 4),
        "forecast_horizon_days":      horizon,

        # Accuracy metrics — null when not evaluated (insufficient history,
        # all candidates failed). Distinct from a literal zero.
        "mae":                        _round_or_none(mae,   4),
        "rmse":                       _round_or_none(rmse,  4),
        "bias":                       _round_or_none(bias,  4),
        "smape":                      _round_or_none(smape, 2),

        # Prediction intervals
        "interval_lower":             intervals.get("interval_lower",  None),
        "interval_upper":             intervals.get("interval_upper",  None),
        "interval_confidence":        intervals.get("interval_confidence", 0.95),
        "interval_empirical_coverage": intervals.get("interval_empirical_coverage", None),

        # Model selection context
        "selection_rationale":        selection.get("selection_rationale", ""),
        "transformation_applied":     transformation,
        "hyperparameters":            hyperparameters or {},

        # Demand profile
        "demand_profile":             profile,

        # Scheduling
        "trained_at":                 now.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "next_review_at":             next_review.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "reeval_trigger":             reeval_trigger,
        "warnings":                   warnings or [],
    }
