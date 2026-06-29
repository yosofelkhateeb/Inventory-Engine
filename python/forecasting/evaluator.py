"""
Model Evaluator.

Provides two evaluation modes:
  1. evaluate_on_folds()  — walk-forward CV across provided folds (Stage 6/8 primary path)
  2. evaluate_model()     — single train/test split (legacy fallback when insufficient CV history)

Metrics: MAE, RMSE, Bias, sMAPE.
MAPE is explicitly excluded — zero-sales days cause division by zero.
Imputed rows (is_imputed=True) are excluded from all metric calculations.
"""
import time
import numpy as np
import pandas as pd
import sys


# ── Metric computation ─────────────────────────────────────────────────────────

def compute_metrics(
    actual: np.ndarray,
    forecast: np.ndarray,
    is_imputed: np.ndarray | None = None,
) -> dict:
    """
    Compute MAE, RMSE, Bias, sMAPE.
    Excludes imputed rows when is_imputed mask is provided.
    """
    a = np.array(actual,   dtype=float)
    f = np.array(forecast, dtype=float)
    n = min(len(a), len(f))
    a, f = a[:n], f[:n]

    if is_imputed is not None and len(is_imputed) >= n:
        mask = ~np.array(is_imputed[:n], dtype=bool)
        if mask.sum() > 0:
            a, f = a[mask], f[mask]

    if len(a) == 0:
        return {"mae": 0.0, "rmse": 0.0, "bias": 0.0, "smape": 0.0}

    errors = f - a
    mae    = float(np.mean(np.abs(errors)))
    rmse   = float(np.sqrt(np.mean(errors ** 2)))
    bias   = float(np.mean(errors))
    denom  = np.abs(a) + np.abs(f)
    smape  = float(np.mean(np.where(denom == 0, 0.0, 2 * np.abs(errors) / denom)) * 100)

    return {"mae": mae, "rmse": rmse, "bias": bias, "smape": smape}


# ── Walk-forward CV evaluation (primary path) ──────────────────────────────────

def evaluate_on_folds(
    model_name: str,
    model_obj,
    full_series: pd.Series,
    folds: list[dict],
    feature_df: pd.DataFrame | None = None,
    exog_col: str | None = None,
) -> dict | None:
    """
    Evaluate a model using the provided walk-forward CV folds.

    Handles different model fit signatures automatically.
    Returns aggregated CV metrics + held-out test metrics.
    Returns None if the model fails on all folds.
    """
    arr      = full_series.values.astype(float)
    imp_arr  = None
    exog_arr = None

    if feature_df is not None and "is_imputed" in feature_df.columns:
        imp_arr = feature_df["is_imputed"].values.astype(int)
    if feature_df is not None and exog_col and exog_col in feature_df.columns:
        exog_arr = feature_df[exog_col].values

    fold_metrics: list[dict] = []
    start_time = time.time()

    for fold in folds:
        te = fold["train_end_idx"]
        vs = fold["val_start_idx"]
        ve = fold["val_end_idx"]

        train = arr[:te + 1].tolist()
        val   = arr[vs:ve + 1]
        val_imp = imp_arr[vs:ve + 1] if imp_arr is not None else None
        exog_train  = exog_arr[:te + 1].tolist() if exog_arr is not None else None
        exog_future = exog_arr[vs:ve + 1].tolist() if exog_arr is not None else None

        try:
            m = _fresh_model(model_obj)
            _fit_model(model_name, m, train, exog_train)
            fc = _forecast_model(model_name, m, len(val), exog_future)
            metrics = compute_metrics(val, np.array(fc), val_imp)
            fold_metrics.append(metrics)
        except Exception as exc:
            print(f"[evaluator] {model_name} fold {fold['fold_num']} failed: {exc}", file=sys.stderr)
            continue

    if not fold_metrics:
        return None

    runtime_sec = time.time() - start_time

    return {
        "cv_mae":    round(float(np.mean([m["mae"]   for m in fold_metrics])), 4),
        "cv_rmse":   round(float(np.mean([m["rmse"]  for m in fold_metrics])), 4),
        "cv_bias":   round(float(np.mean([m["bias"]  for m in fold_metrics])), 4),
        "cv_smape":  round(float(np.mean([m["smape"] for m in fold_metrics])), 2),
        "n_folds":   len(fold_metrics),
        "runtime_sec": round(runtime_sec, 2),
    }


def evaluate_on_holdout(
    model_name: str,
    model_obj,
    train_series: list[float],
    holdout_actual: np.ndarray,
    exog_train: list[float] | None = None,
    exog_holdout: list[float] | None = None,
    holdout_is_imputed: np.ndarray | None = None,
) -> dict:
    """Evaluate on the held-out test set."""
    try:
        _fit_model(model_name, model_obj, train_series, exog_train)
        fc = _forecast_model(model_name, model_obj, len(holdout_actual), exog_holdout)
        metrics = compute_metrics(holdout_actual, np.array(fc), holdout_is_imputed)
        return {
            "test_mae":   round(metrics["mae"],   4),
            "test_rmse":  round(metrics["rmse"],  4),
            "test_bias":  round(metrics["bias"],  4),
            "test_smape": round(metrics["smape"], 2),
        }
    except Exception as exc:
        print(f"[evaluator] {model_name} holdout failed: {exc}", file=sys.stderr)
        return {"test_mae": float("inf"), "test_rmse": 0.0, "test_bias": 0.0, "test_smape": 0.0}


# ── Legacy single train/test split (fallback) ──────────────────────────────────

MIN_TEST_DAYS = 30


def evaluate_model(
    model_name: str,
    model_obj,
    series: list[float],
    exog: list[float] | None = None,
    future_exog: list[float] | None = None,
) -> dict | None:
    """
    Fit on train split (80%), forecast test split (20%, min 30 days).
    Legacy API — used when insufficient history for walk-forward CV.
    Returns None if model fails.
    """
    n        = len(series)
    test_len = max(MIN_TEST_DAYS, int(n * 0.2))
    if test_len >= n:
        test_len = 1
    train = series[:n - test_len]
    test  = series[n - test_len:]

    if len(train) < 2:
        return None

    try:
        _fit_model(model_name, model_obj, train, exog[:len(train)] if exog else None)
        fc = _forecast_model(model_name, model_obj, len(test), future_exog)
        metrics = compute_metrics(np.array(test), np.array(fc))
        return {
            "mae":   round(metrics["mae"],   4),
            "rmse":  round(metrics["rmse"],  4),
            "bias":  round(metrics["bias"],  4),
            "smape": round(metrics["smape"], 2),
        }
    except Exception as exc:
        print(f"[evaluator] {model_name} failed: {exc}", file=sys.stderr)
        return None


# ── WMAPE (portfolio-level) ─────────────────────────────────────────────────────

def compute_wmape(actual_by_sku: list[float], forecast_by_sku: list[float]) -> float:
    """
    Portfolio-level Weighted MAPE.
    WMAPE = sum(|actual - forecast|) / sum(|actual|) × 100
    """
    total_actual = sum(abs(a) for a in actual_by_sku)
    if total_actual == 0:
        return 0.0
    total_error = sum(abs(a - f) for a, f in zip(actual_by_sku, forecast_by_sku))
    return (total_error / total_actual) * 100


# ── Shared model helpers ───────────────────────────────────────────────────────

def _fresh_model(model_obj):
    """Return a new unfitted instance of the same class.

    Preserves constructor flags that affect fit behaviour (e.g. SARIMAX's
    log_transform). Without this, walk-forward CV would silently train each
    fold on raw data while the production fit ran on log-scale, contaminating
    candidate selection.
    """
    cls = model_obj.__class__
    if hasattr(model_obj, "_log_transform"):
        try:
            return cls(log_transform=model_obj._log_transform)
        except TypeError:
            pass
    return cls()


def _fit_model(name: str, model, train: list[float], exog: list[float] | None) -> None:
    if name == "sarimax":
        model.fit(train, exog=exog)
    elif name == "lightgbm":
        promo = [int(v) for v in exog] if exog else None
        model.fit(train, promo=promo)
    elif name == "prophet":
        promo = [int(v) for v in exog] if exog else None
        model.fit(train, promotion_series=promo)
    else:
        model.fit(train)


def _forecast_model(name: str, model, horizon: int, future_exog: list[float] | None) -> list[float]:
    if name == "sarimax":
        return model.forecast_series(horizon, future_exog=future_exog)
    elif name == "lightgbm":
        future_promo = [int(v) for v in future_exog] if future_exog else None
        return model.forecast_series(horizon, future_promo=future_promo)
    elif name == "prophet":
        future_promo = [int(v) for v in future_exog] if future_exog else None
        return model.forecast_series(horizon, future_promo=future_promo)
    else:
        return model.forecast_series(horizon)
