"""
Stage 4: Baseline Models

Implements four baselines: naive, seasonal naive, moving average (3 window sizes),
and weighted historical average. All evaluated on the same walk-forward CV folds
as candidate models.

Baseline results feed into the selection gate: a candidate model is only eligible
if its CV MAE beats the best baseline's CV MAE.
"""
import numpy as np
import pandas as pd

from pipeline._config import load_config


def evaluate(
    full_series: pd.Series,
    folds: list[dict],
) -> dict:
    """
    Evaluate all baselines on the given CV folds.

    Args:
        full_series: pd.Series with DatetimeIndex, quantity_sold
        folds:       list of {'train_idx': [...], 'val_idx': [...]} from validation.py

    Returns:
        {
            best_baseline_name : str,
            best_baseline_cv_mae: float,
            all_results: {
                baseline_name: {cv_mae, cv_rmse, cv_bias, cv_smape}
            }
        }
    """
    cfg = load_config()
    windows  = cfg["baselines"]["moving_average_windows"]
    weights  = cfg["baselines"]["weighted_average_weights"]

    arr = full_series.values.astype(float)

    baselines: dict[str, list[dict]] = {}

    for fold in folds:
        train_end = fold["train_end_idx"]
        val_start = fold["val_start_idx"]
        val_end   = fold["val_end_idx"]

        train_arr = arr[:train_end + 1]
        val_arr   = arr[val_start:val_end + 1]
        val_len   = len(val_arr)

        if val_len == 0 or len(train_arr) == 0:
            continue

        # ── Naive (last value) ─────────────────────────────────────────────────
        naive_fc = np.full(val_len, train_arr[-1])
        _add_fold(baselines, "naive", val_arr, naive_fc)

        # ── Seasonal naive (lag-7) ─────────────────────────────────────────────
        if len(train_arr) >= 7:
            sn_fc = np.array([train_arr[-(7 - (i % 7))] for i in range(val_len)])
        else:
            sn_fc = np.full(val_len, train_arr[-1])
        _add_fold(baselines, "seasonal_naive", val_arr, sn_fc)

        # ── Moving average (each window) ───────────────────────────────────────
        for w in windows:
            if len(train_arr) >= w:
                ma_val = float(np.mean(train_arr[-w:]))
            else:
                ma_val = float(np.mean(train_arr))
            ma_fc = np.full(val_len, ma_val)
            _add_fold(baselines, f"moving_average_{w}d", val_arr, ma_fc)

        # ── Weighted historical average (60/30/10 weighting) ──────────────────
        wha_fc = np.full(val_len, _weighted_historical_avg(train_arr, weights))
        _add_fold(baselines, "weighted_historical_average", val_arr, wha_fc)

    # ── Aggregate folds → mean CV metrics ─────────────────────────────────────
    all_results: dict[str, dict] = {}
    for name, fold_metrics in baselines.items():
        if not fold_metrics:
            continue
        all_results[name] = {
            "cv_mae":   round(float(np.mean([m["mae"]   for m in fold_metrics])), 4),
            "cv_rmse":  round(float(np.mean([m["rmse"]  for m in fold_metrics])), 4),
            "cv_bias":  round(float(np.mean([m["bias"]  for m in fold_metrics])), 4),
            "cv_smape": round(float(np.mean([m["smape"] for m in fold_metrics])), 2),
        }

    if not all_results:
        return {"best_baseline_name": "naive", "best_baseline_cv_mae": float("inf"), "all_results": {}}

    best_name = min(all_results, key=lambda k: all_results[k]["cv_mae"])
    return {
        "best_baseline_name":  best_name,
        "best_baseline_cv_mae": all_results[best_name]["cv_mae"],
        "all_results":          all_results,
    }


# ── Helpers ────────────────────────────────────────────────────────────────────

def _weighted_historical_avg(train: np.ndarray, weights: list[float]) -> float:
    """
    60/30/10 weighted average across 3 yearly windows.
    Falls back gracefully when not enough history exists.
    """
    n = len(train)
    year = 365
    segments = []

    if n >= year * 2:
        segments.append((train[n - year * 3:n - year * 2] if n >= year * 3 else train[:n - year * 2], weights[2]))
        segments.append((train[n - year * 2:n - year],  weights[1]))
        segments.append((train[n - year:],               weights[0]))
    elif n >= year:
        segments.append((train[n - year:], weights[0] + weights[1]))
    else:
        segments.append((train, sum(weights)))

    total_w = 0.0
    total_v = 0.0
    for seg, w in segments:
        if len(seg) > 0:
            total_v += float(np.mean(seg)) * w
            total_w += w

    return total_v / total_w if total_w > 0 else 0.0


def _add_fold(baselines: dict, name: str, actual: np.ndarray, forecast: np.ndarray) -> None:
    if name not in baselines:
        baselines[name] = []
    baselines[name].append(_metrics(actual, forecast))


def _metrics(actual: np.ndarray, forecast: np.ndarray) -> dict:
    n = min(len(actual), len(forecast))
    a, f = actual[:n].astype(float), forecast[:n].astype(float)
    errors = f - a
    mae  = float(np.mean(np.abs(errors)))
    rmse = float(np.sqrt(np.mean(errors ** 2)))
    bias = float(np.mean(errors))
    denom = np.abs(a) + np.abs(f)
    smape = float(np.mean(np.where(denom == 0, 0.0, 2 * np.abs(errors) / denom)) * 100)
    return {"mae": mae, "rmse": rmse, "bias": bias, "smape": smape}
