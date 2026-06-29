"""
Stage 9: Diagnostics and Error Analysis

Runs on the top 2 candidate models after CV evaluation.
Writes diagnostic JSON files to reports/diagnostics_{sku_id}_{tenant_id}/.
"""
import json
import logging
import os

import numpy as np
import pandas as pd

from pipeline._config import load_config

logger = logging.getLogger(__name__)


def run(
    model_name: str,
    actual: np.ndarray,
    forecast: np.ndarray,
    df: pd.DataFrame,
    sku_id: int,
    tenant_id: int,
    reports_dir: str,
) -> dict:
    """
    Compute diagnostics for a model's held-out test predictions.

    Args:
        model_name : name of the model being diagnosed
        actual     : array of actual values (held-out test set)
        forecast   : array of model predictions (same length as actual)
        df         : full preprocessed DataFrame (for promotion flags etc.)
        sku_id     : for file naming
        tenant_id  : for file naming
        reports_dir: root reports directory

    Returns diagnostic summary dict.
    """
    cfg        = load_config()
    acf_lags   = int(cfg["evaluation"]["residual_acf_lags"])
    acf_sig    = float(cfg["evaluation"]["residual_acf_significance"])

    diag_dir = os.path.join(reports_dir, f"diagnostics_{sku_id}_{tenant_id}")
    os.makedirs(diag_dir, exist_ok=True)

    n = min(len(actual), len(forecast))
    if n == 0:
        return _empty_diagnostics(model_name)

    a   = np.array(actual[:n],   dtype=float)
    f   = np.array(forecast[:n], dtype=float)
    res = f - a  # signed residuals

    # ── 1. Residual autocorrelation (Ljung-Box) ────────────────────────────────
    ljung_p  = None
    residual_autocorrelation_concern = False
    try:
        from statsmodels.stats.diagnostic import acorr_ljungbox
        lb = acorr_ljungbox(res, lags=[min(acf_lags, n // 2)], return_df=True)
        ljung_p = float(lb["lb_pvalue"].iloc[-1])
        residual_autocorrelation_concern = ljung_p < acf_sig
    except Exception as exc:
        logger.warning(f"Diagnostics: Ljung-Box failed for {model_name}: {exc}")

    # ── 2. Seasonal residual pattern (mean residual by day-of-week) ───────────
    seasonal_pattern_detected = False
    if hasattr(df.index, 'dayofweek') and len(df.index) >= n:
        test_index = df.index[-n:]
        dow_residuals: dict[int, list[float]] = {}
        for i, ts in enumerate(test_index):
            d = ts.dayofweek
            dow_residuals.setdefault(d, []).append(float(res[i]))
        dow_means = {d: np.mean(v) for d, v in dow_residuals.items()}
        if dow_means:
            rng = max(dow_means.values()) - min(dow_means.values())
            seasonal_pattern_detected = (rng > np.std(res) * 0.5)

    # ── 3. Promotion / event period error ─────────────────────────────────────
    promo_mae    = None
    baseline_mae = None
    if hasattr(df, 'index') and len(df.index) >= n:
        test_df = df.iloc[-n:]
        promo_mask   = test_df["is_promotion"].values.astype(bool)
        non_promo    = ~promo_mask
        if promo_mask.sum() > 0:
            promo_mae = float(np.mean(np.abs(res[promo_mask])))
        if non_promo.sum() > 0:
            baseline_mae = float(np.mean(np.abs(res[non_promo])))

    # ── 4. Directional accuracy ────────────────────────────────────────────────
    directional_accuracy_pct = None
    if n > 1:
        actual_dir   = np.diff(a)
        forecast_dir = np.diff(f)
        correct      = (np.sign(actual_dir) == np.sign(forecast_dir)).sum()
        directional_accuracy_pct = round(float(correct / max(len(actual_dir), 1) * 100), 1)

    # ── 5. Over/under distribution ─────────────────────────────────────────────
    over_pct  = round(float((res > 0).sum() / n * 100), 1)
    under_pct = round(float((res < 0).sum() / n * 100), 1)
    if abs(over_pct - under_pct) > 20:
        bias_skew = "over_forecast" if over_pct > under_pct else "under_forecast"
    elif abs(over_pct - under_pct) > 10:
        bias_skew = f"slight_{'over' if over_pct > under_pct else 'under'}forecast"
    else:
        bias_skew = "balanced"

    summary = {
        "model_name":                        model_name,
        "ljung_box_p_value":                 round(ljung_p, 4) if ljung_p is not None else None,
        "residual_autocorrelation_concern":   residual_autocorrelation_concern,
        "seasonal_residual_pattern_detected": seasonal_pattern_detected,
        "promotion_period_mae":              round(promo_mae, 4) if promo_mae is not None else None,
        "baseline_period_mae":               round(baseline_mae, 4) if baseline_mae is not None else None,
        "directional_accuracy_pct":          directional_accuracy_pct,
        "bias_skew":                         bias_skew,
        "over_forecast_pct":                 over_pct,
        "under_forecast_pct":                under_pct,
    }

    path = os.path.join(diag_dir, f"diagnostics_summary_{model_name}.json")
    with open(path, "w", encoding="utf-8") as fp:
        json.dump(summary, fp, indent=2, default=str)

    return summary


def _empty_diagnostics(model_name: str) -> dict:
    return {
        "model_name":                        model_name,
        "ljung_box_p_value":                 None,
        "residual_autocorrelation_concern":   False,
        "seasonal_residual_pattern_detected": False,
        "promotion_period_mae":              None,
        "baseline_period_mae":               None,
        "directional_accuracy_pct":          None,
        "bias_skew":                         "unknown",
        "over_forecast_pct":                 None,
        "under_forecast_pct":                None,
    }
