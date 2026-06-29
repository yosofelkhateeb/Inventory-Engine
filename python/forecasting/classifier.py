"""
SKU Demand Profile Classifier.

Reads sales history + category thresholds from the input JSON and returns:
  - A demand profile dict (volume_tier, volatility, intermittency, etc.)
  - A candidate model shortlist

If history is below min_history_days: returns skip_competition=True
with ets_fallback assigned directly.
"""

import numpy as np
import sys
from scipy import stats as scipy_stats


# ─── Candidate model shortlist mapping ────────────────────────────────────────
# Each rule is evaluated in order; first match wins.

def _get_candidate_shortlist(profile: dict) -> list[str]:
    vol   = profile["volatility"]
    inter = profile["intermittency"]
    seas  = profile["seasonality_detected"]
    vt    = profile["volume_tier"]

    if inter == "intermittent":
        return ["croston", "ets_fallback"]

    if vt == "low":
        if seas:
            return ["holt_winters", "prophet"]
        return ["holt_winters", "ets_fallback"]

    # medium or high volume
    if vol == "stable":
        if seas:
            return ["holt_winters", "sarimax", "prophet"]
        return ["holt_winters", "sarimax"]

    if vol in ("moderate", "erratic"):
        if seas:
            return ["sarimax", "lightgbm", "prophet"]
        return ["sarimax", "lightgbm"]

    # Fallback
    return ["holt_winters", "ets_fallback"]


# ─── Main classify function ───────────────────────────────────────────────────

def classify(input_data: dict) -> dict:
    """
    Classify a SKU and return its demand profile + candidate model shortlist.

    input_data keys used:
        sales_history        list of {date, quantity_sold, is_promotion}
        sku_category         str  (equipment / accessory / bundle)
        thresholds           dict {min_history_days, seasonality_min_days,
                                   intermittency_threshold}

    Returns:
        {
            "skip_competition": bool,
            "model": str (only when skip_competition=True),
            "profile": {...},
            "candidates": [str, ...]
        }
    """
    thresholds = input_data.get("thresholds", {})
    min_history_days   = int(thresholds.get("min_history_days", 90))
    seasonality_min    = int(thresholds.get("seasonality_min_days", 365))
    intermit_threshold = float(thresholds.get("intermittency_threshold", 0.20))

    sales_history = input_data.get("sales_history", [])

    # Filter to baseline days only (exclude promotion days for variability calc)
    all_days  = [float(r["quantity_sold"]) for r in sales_history]
    base_days = [
        float(r["quantity_sold"])
        for r in sales_history
        if not r.get("is_promotion", False)
    ]
    history_days = len(all_days)

    # ── Insufficient history gate ────────────────────────────────────────────
    if history_days < min_history_days:
        return {
            "skip_competition": True,
            "model": "ets_fallback",
            "profile": {
                "volume_tier":          "low",
                "volatility":           "stable",
                "intermittency":        "continuous",
                "seasonality_detected": False,
                "trend_direction":      "flat",
                "history_days_used":    history_days,
            },
            "candidates": ["ets_fallback"],
        }

    arr      = np.array(all_days)
    base_arr = np.array(base_days) if base_days else arr

    # ── Volume tier (avg daily, trailing 90 days) ────────────────────────────
    trailing_90 = arr[-90:] if len(arr) >= 90 else arr
    avg_daily = float(np.mean(trailing_90))

    if avg_daily > 5.0:
        volume_tier = "high"
    elif avg_daily >= 1.0:
        volume_tier = "medium"
    else:
        volume_tier = "low"

    # ── Volatility (CV on baseline, trailing 90) ─────────────────────────────
    base_90 = base_arr[-90:] if len(base_arr) >= 90 else base_arr
    mean_b  = float(np.mean(base_90))
    std_b   = float(np.std(base_90))
    cv      = (std_b / mean_b) if mean_b > 0 else 0.0

    if cv < 0.3:
        volatility = "stable"
    elif cv <= 0.6:
        volatility = "moderate"
    else:
        volatility = "erratic"

    # ── Intermittency (% zero days over full history) ────────────────────────
    zero_pct = float(np.sum(arr == 0) / len(arr)) if len(arr) > 0 else 0.0
    intermittency = "intermittent" if zero_pct >= intermit_threshold else "continuous"

    # ── Seasonality (ACF test at lag=7 — requires seasonality_min_days) ──────
    seasonality_detected = False
    if history_days >= seasonality_min:
        try:
            from statsmodels.tsa.stattools import acf
            acf_vals = acf(arr, nlags=14, fft=True)
            # Significant autocorrelation at weekly lag (lag=7)
            n = len(arr)
            conf_bound = 1.96 / np.sqrt(n)
            seasonality_detected = bool(abs(acf_vals[7]) > conf_bound)
        except Exception:
            pass

    # ── Trend (linear regression over trailing 180 days) ─────────────────────
    trend_arr = arr[-180:] if len(arr) >= 180 else arr
    x = np.arange(len(trend_arr))
    try:
        slope, _, _, p_value, _ = scipy_stats.linregress(x, trend_arr)
        if p_value < 0.05:
            trend_direction = "upward" if slope > 0 else "declining"
        else:
            trend_direction = "flat"
    except Exception:
        trend_direction = "flat"

    profile = {
        "volume_tier":          volume_tier,
        "volatility":           volatility,
        "intermittency":        intermittency,
        "seasonality_detected": seasonality_detected,
        "trend_direction":      trend_direction,
        "history_days_used":    history_days,
    }

    candidates = _get_candidate_shortlist(profile)

    return {
        "skip_competition": False,
        "profile":          profile,
        "candidates":       candidates,
    }
