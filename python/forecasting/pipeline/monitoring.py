"""
Stage 15: Python-side Monitoring Checks

Runs on each pipeline execution. Returns a list of warnings that are
included in the output JSON and surfaced in the UI via the warnings[] array.

Checks:
  1. Distribution shift — mean/std of recent 30 days vs training distribution
  2. Model staleness — days since last training
  3. Interval coverage tracking (on re-evaluation runs)
"""
import logging
from datetime import datetime, timezone

import numpy as np

from pipeline._config import load_config

logger = logging.getLogger(__name__)


def check(
    full_series_values: list[float],
    trained_at: str | None = None,
    training_mean: float | None = None,
    training_std: float | None = None,
    empirical_coverage: float | None = None,
) -> dict:
    """
    Run all monitoring checks and return a warnings list.

    Args:
        full_series_values: list of daily quantities (full history, clean)
        trained_at:         ISO8601 string of last training time (from registry)
        training_mean:      mean of training distribution at last training
        training_std:       std of training distribution at last training
        empirical_coverage: latest interval coverage result

    Returns:
        {"warnings": [str, ...]}
    """
    cfg       = load_config()
    mean_thr  = float(cfg["monitoring"]["distribution_shift_mean_threshold"])
    std_thr   = float(cfg["monitoring"]["distribution_shift_std_threshold"])
    window    = int(cfg["monitoring"]["bias_drift_window_days"])
    stale_d   = int(cfg["monitoring"]["model_staleness_days"])

    warnings: list[str] = []

    arr = np.array(full_series_values, dtype=float)
    n   = len(arr)

    # ── 1. Distribution shift check ────────────────────────────────────────────
    if n >= window and training_mean is not None and training_std is not None:
        recent = arr[-window:]
        recent_mean = float(np.mean(recent))
        recent_std  = float(np.std(recent))

        if training_mean > 0:
            mean_shift = abs(recent_mean - training_mean) / training_mean
            if mean_shift > mean_thr:
                warnings.append(
                    f"distribution_shift_warning: mean shifted {mean_shift:.0%} "
                    f"({training_mean:.2f} → {recent_mean:.2f}) — full re-evaluation recommended."
                )

        if training_std > 0:
            std_shift = abs(recent_std - training_std) / training_std
            if std_shift > std_thr:
                warnings.append(
                    f"distribution_shift_warning: std shifted {std_shift:.0%} "
                    f"({training_std:.2f} → {recent_std:.2f}) — demand volatility has changed."
                )

    # ── 2. Model staleness check ───────────────────────────────────────────────
    if trained_at is not None:
        try:
            trained_dt = datetime.fromisoformat(trained_at.replace("Z", "+00:00"))
            now_dt     = datetime.now(timezone.utc)
            days_since = (now_dt - trained_dt).days
            if days_since > stale_d:
                warnings.append(
                    f"model_stale_warning: model was trained {days_since} days ago "
                    f"(threshold: {stale_d} days) — consider running forecast:sweep."
                )
        except Exception as exc:
            logger.warning(f"Monitoring: could not parse trained_at='{trained_at}': {exc}")

    # ── 3. Interval coverage tracking ─────────────────────────────────────────
    if empirical_coverage is not None and empirical_coverage < 0.70:
        warnings.append(
            f"interval_coverage_warning: empirical coverage={empirical_coverage:.1%} "
            f"is below 70% — intervals may be unreliable."
        )

    return {"warnings": warnings}
