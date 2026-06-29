"""
Stage 3: Exploratory Time-Series Analysis

Runs only on reeval_trigger = 'scheduled' or 'new_sku'.
Outputs written to reports/eda_{sku_id}_{tenant_id}/.
Returns an eda_summary dict consumed by classifier and main.py.
"""
import json
import os
import logging

import numpy as np
import pandas as pd
from scipy import stats as scipy_stats

from pipeline._config import load_config

logger = logging.getLogger(__name__)


def run(
    baseline_series: pd.Series,
    sku_id: int,
    tenant_id: int,
    reports_dir: str,
) -> dict:
    """
    Run EDA on the baseline (non-promotional) series.

    Returns:
        {
            trend_direction    : str  — 'upward'/'flat'/'declining'
            trend_slope        : float
            trend_r2           : float
            seasonality_detected: bool
            seasonal_period    : int | None
            cv_value           : float
            structural_breaks  : list[str]
            recommended_transformation: str
            acf_values         : list[float]  — first 30 lags
            history_days       : int
        }
    """
    eda_dir = os.path.join(reports_dir, f"eda_{sku_id}_{tenant_id}")
    os.makedirs(eda_dir, exist_ok=True)

    series = baseline_series.dropna()
    n = len(series)

    if n < 14:
        result = _minimal_eda(sku_id, eda_dir)
        logger.warning(f"EDA: insufficient baseline history ({n} days) for SKU {sku_id}")
        return result

    arr = series.values.astype(float)

    # ── 1. Trend analysis (OLS) ────────────────────────────────────────────────
    x = np.arange(n)
    slope, intercept, r_value, p_value, _ = scipy_stats.linregress(x, arr)
    trend_r2 = float(r_value ** 2)

    if p_value < 0.05:
        trend_direction = "upward" if slope > 0 else "declining"
    else:
        trend_direction = "flat"

    trend_result = {
        "slope":     round(float(slope), 6),
        "r2":        round(trend_r2, 4),
        "p_value":   round(float(p_value), 4),
        "direction": trend_direction,
    }
    _write_json(trend_result, os.path.join(eda_dir, "trend.json"))

    # ── 2. Seasonality check (STL / ACF at lag-7) ─────────────────────────────
    cfg = load_config()
    acf_values: list[float] = []
    seasonality_detected = False
    seasonal_period: int | None = None

    try:
        from statsmodels.tsa.stattools import acf
        nlags = min(30, n // 2 - 1)
        if nlags >= 7:
            acf_vals = acf(arr, nlags=nlags, fft=True)
            acf_values = [round(float(v), 4) for v in acf_vals]
            conf_bound = 1.96 / np.sqrt(n)
            seasonality_detected = bool(abs(acf_vals[7]) > conf_bound)
            if seasonality_detected:
                seasonal_period = 7
    except Exception as exc:
        logger.warning(f"EDA: ACF failed for SKU {sku_id}: {exc}")

    seasonality_result = {
        "detected": seasonality_detected,
        "seasonal_period": seasonal_period,
        "test": "acf_lag7",
        "n_samples": n,
    }
    _write_json(seasonality_result, os.path.join(eda_dir, "seasonality.json"))
    _write_json({"acf_values": acf_values}, os.path.join(eda_dir, "acf_values.json"))

    # ── 3. Rolling statistics (7-day + 30-day) ────────────────────────────────
    s = pd.Series(arr)
    rm7  = s.rolling(7,  min_periods=4).mean().dropna()
    rs7  = s.rolling(7,  min_periods=4).std().dropna()
    rm30 = s.rolling(30, min_periods=15).mean().dropna()
    rs30 = s.rolling(30, min_periods=15).std().dropna()

    # ── 4. Variance stability (CV by quarter) ─────────────────────────────────
    mean_overall = float(np.mean(arr)) if n > 0 else 0.0
    std_overall  = float(np.std(arr))  if n > 0 else 0.0
    cv_value     = (std_overall / mean_overall) if mean_overall > 0 else 0.0

    # Check if variance grows with level (flag for log transform)
    recommended_transformation = "none"
    if len(rm30) >= 10 and len(rs30) >= 10:
        corr = float(np.corrcoef(rm30.values, rs30.values[:len(rm30)])[0, 1])
        if corr > 0.6:
            recommended_transformation = "log1p"

    # ── 5. Structural break detection (Chow-style rolling variance) ───────────
    structural_breaks: list[str] = []
    try:
        structural_breaks = _detect_structural_breaks(arr, series.index)
    except Exception as exc:
        logger.warning(f"EDA: structural break detection failed for SKU {sku_id}: {exc}")

    _write_json({"break_dates": structural_breaks}, os.path.join(eda_dir, "structural_breaks.json"))

    # ── 6. Summary text ───────────────────────────────────────────────────────
    summary_lines = [
        f"SKU {sku_id} EDA Summary",
        f"History: {n} baseline days",
        f"Trend: {trend_direction} (slope={slope:.4f}, p={p_value:.4f})",
        f"Seasonality: {'Detected (period=7)' if seasonality_detected else 'None detected'}",
        f"CV: {cv_value:.3f}",
        f"Structural breaks: {structural_breaks if structural_breaks else 'None'}",
        f"Recommended transformation: {recommended_transformation}",
    ]
    _write_text("\n".join(summary_lines), os.path.join(eda_dir, "eda_summary.txt"))

    return {
        "trend_direction":              trend_direction,
        "trend_slope":                  round(float(slope), 6),
        "trend_r2":                     round(trend_r2, 4),
        "seasonality_detected":         seasonality_detected,
        "seasonal_period":              seasonal_period,
        "cv_value":                     round(cv_value, 4),
        "structural_breaks":            structural_breaks,
        "recommended_transformation":   recommended_transformation,
        "acf_values":                   acf_values,
        "history_days":                 n,
    }


# ── Helpers ────────────────────────────────────────────────────────────────────

def _minimal_eda(sku_id: int, eda_dir: str) -> dict:
    result = {
        "trend_direction": "flat", "trend_slope": 0.0, "trend_r2": 0.0,
        "seasonality_detected": False, "seasonal_period": None,
        "cv_value": 0.0, "structural_breaks": [],
        "recommended_transformation": "none",
        "acf_values": [], "history_days": 0,
    }
    _write_text(f"EDA skipped — insufficient history for SKU {sku_id}", os.path.join(eda_dir, "eda_summary.txt"))
    return result


def _detect_structural_breaks(arr: np.ndarray, index: pd.Index) -> list[str]:
    """
    Simple CUSUM-style structural break detection.
    Flags dates where the cumulative sum of deviations crosses ±2σ from the mean.
    """
    if len(arr) < 30:
        return []

    mean_a = np.mean(arr)
    std_a  = np.std(arr)
    if std_a == 0:
        return []

    deviations = (arr - mean_a) / std_a
    cusum = np.cumsum(deviations)
    threshold = 2.0 * np.sqrt(len(arr))
    breaks = []

    crossed = False
    for i, v in enumerate(cusum):
        if abs(v) > threshold and not crossed:
            if hasattr(index, '__getitem__') and i < len(index):
                idx_val = index[i]
                if hasattr(idx_val, 'strftime'):
                    breaks.append(idx_val.strftime("%Y-%m-%d"))
                else:
                    breaks.append(str(idx_val))
            crossed = True
        elif abs(v) <= threshold * 0.5:
            crossed = False

    return breaks[:3]  # cap at 3 break points


def _write_json(data: dict, path: str) -> None:
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, default=str)


def _write_text(text: str, path: str) -> None:
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)
