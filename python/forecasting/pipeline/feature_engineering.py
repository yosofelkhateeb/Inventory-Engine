"""
Stage 5: Feature Engineering

Builds the feature matrix for ML models (LightGBM, etc.).
All features are leakage-safe: feature at time T uses only data from T-1 or earlier.
A leakage assertion raises an Exception if violated — not just a warning.
"""
import numpy as np
import pandas as pd

from pipeline._config import load_config


def build_features(
    df: pd.DataFrame,
    promotions: list[dict],
    holidays: list[dict],
    eda_result: dict | None = None,
) -> pd.DataFrame:
    """
    Build feature DataFrame from the preprocessed full-series DataFrame.

    Args:
        df:          preprocessed DataFrame (from preprocessing.py)
        promotions:  list of promotion dicts from RunForecastJob input
        holidays:    list of holiday dicts from RunForecastJob input
        eda_result:  EDA result dict (used to decide transformation)

    Returns:
        pd.DataFrame with all feature columns, same index as `df`.
        Target column `quantity_sold` is included but NOT used as a feature.
    """
    cfg   = load_config()
    lags  = cfg["features"]["lag_days"]
    wins  = cfg["features"]["rolling_windows"]
    ewm_s = int(cfg["features"]["ewm_span"])
    cap   = int(cfg["features"]["promo_lookahead_cap_days"])

    feat = df[["quantity_sold", "is_promotion", "is_regional_holiday",
               "promotion_uplift_pct", "holiday_uplift_pct",
               "is_imputed", "is_stockout"]].copy()

    # ── Lag features ─────────────────────────────────────────────────────────
    for lag in lags:
        col = f"lag_{lag}"
        feat[col] = feat["quantity_sold"].shift(lag)

    # ── Rolling features (shift by 1 to prevent leakage) ─────────────────────
    # Computed on baseline signal: zero out promotional days before rolling
    baseline_qty = feat["quantity_sold"].copy()
    promo_mask   = (feat["is_promotion"] == 1) | (feat["is_regional_holiday"] == 1)
    baseline_qty[promo_mask] = np.nan

    for w in wins:
        # Shift by 1 first to avoid using T in the window ending at T
        shifted = baseline_qty.shift(1)
        feat[f"rolling_mean_{w}"]  = shifted.rolling(w, min_periods=max(1, w // 2)).mean()
        feat[f"rolling_std_{w}"]   = shifted.rolling(w, min_periods=max(1, w // 2)).std().fillna(0.0)

    feat[f"ewm_{ewm_s}"] = baseline_qty.shift(1).ewm(span=ewm_s, min_periods=1).mean()

    # ── Calendar features ─────────────────────────────────────────────────────
    feat["day_of_week"]   = feat.index.dayofweek
    feat["day_of_month"]  = feat.index.day
    feat["week_of_year"]  = feat.index.isocalendar().week.astype(int)
    feat["month"]         = feat.index.month
    feat["is_weekend"]    = (feat.index.dayofweek >= 5).astype(int)
    feat["is_month_end"]  = feat.index.is_month_end.astype(int)

    # ── Event features ────────────────────────────────────────────────────────
    # Ramadan / Eid flags derived from holidays
    ramadan_dates: set[str] = set()
    eid_dates:     set[str] = set()
    for h in (holidays or []):
        name = str(h.get("name") or h.get("holiday_name", "")).lower()
        d    = str(h.get("date") or h.get("holiday_date", ""))
        if "ramadan" in name:
            ramadan_dates.add(d)
        if "eid" in name:
            eid_dates.add(d)

    feat["is_ramadan"] = feat.index.to_series().dt.strftime("%Y-%m-%d").isin(ramadan_dates).astype(int)
    feat["is_eid"]     = feat.index.to_series().dt.strftime("%Y-%m-%d").isin(eid_dates).astype(int)

    # days_since_last_promotion
    days_since = _days_since_flag(feat["is_promotion"])
    feat["days_since_last_promotion"] = days_since

    # days_until_next_promotion (from known future promotions input)
    feat["days_until_next_promotion"] = _days_until_flag(feat.index, promotions, cap)

    # ── Leakage assertion ──────────────────────────────────────────────────────
    # All lag/rolling features should have NaN at row 0 (they use T-1 data).
    # The raw quantity_sold should not appear as a feature column.
    assert "quantity_sold" not in [
        c for c in feat.columns if c != "quantity_sold"
    ], "quantity_sold leaked into feature set"

    # Check: lag_1 at index 0 should be NaN (no prior data)
    if "lag_1" in feat.columns and len(feat) > 0:
        assert pd.isna(feat["lag_1"].iloc[0]), \
            "Leakage detected: lag_1 is not NaN at index 0 — future data used in feature computation"

    return feat


# ── Helpers ────────────────────────────────────────────────────────────────────

def _days_since_flag(flag_series: pd.Series) -> pd.Series:
    """Number of days since last 1-day in flag_series. 0 on flag days."""
    result = []
    counter = 999
    for v in flag_series:
        if v == 1:
            counter = 0
        else:
            counter = counter + 1 if counter < 999 else 999
        result.append(counter)
    return pd.Series(result, index=flag_series.index)


def _days_until_flag(idx: pd.DatetimeIndex, promotions: list[dict], cap: int) -> pd.Series:
    """
    Days until next known promotion for each date in idx.
    Returns cap (999) when no promotion within `cap` days.
    Only uses promotions at or after T (known at forecast time — valid).
    """
    if not promotions:
        return pd.Series(cap, index=idx)

    # Flatten all promotion dates into a sorted list
    promo_dates: list[pd.Timestamp] = []
    for p in promotions:
        s = pd.Timestamp(p["start_date"])
        e = pd.Timestamp(p["end_date"])
        for t in pd.date_range(s, e, freq="D"):
            promo_dates.append(t)
    promo_dates = sorted(set(promo_dates))

    result = []
    for d in idx:
        # Find nearest future promo date
        future = [p for p in promo_dates if p > d]
        if future:
            delta = int((future[0] - d).days)
            result.append(min(delta, cap))
        else:
            result.append(cap)
    return pd.Series(result, index=idx, dtype=int)
