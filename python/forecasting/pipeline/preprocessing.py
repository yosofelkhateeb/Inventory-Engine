"""
Stage 2: Preprocessing

Builds a clean daily pandas DataFrame from the audit result.
Handles stockout imputation, reporting gap imputation, outlier capping,
and splits into baseline_series vs full_series.

Returns a PreprocessResult dict containing:
    df              : pd.DataFrame  — full series with all flags
    baseline_series : pd.Series     — non-promotional, non-holiday days only
    full_series     : pd.Series     — all days (for models accepting exog vars)
"""
import numpy as np
import pandas as pd

from pipeline._config import load_config


def preprocess(
    audit_result: dict,
    promotions: list[dict],
    holidays: list[dict],
) -> dict:
    """
    Build clean daily DataFrame from audit result.

    Returns:
        {
            df             : pd.DataFrame,
            baseline_series: pd.Series,
            full_series    : pd.Series,
            transformation : str  # 'none' or 'log1p'
        }
    """
    cfg = load_config()
    cap_sigma = float(cfg["audit"]["outlier_cap_sigma"])

    all_dates   = audit_result["all_dates"]
    date_to_qty = audit_result["date_to_qty"]
    classified  = audit_result["classified_gaps"]
    outliers    = set(audit_result["outlier_candidates"])

    if not all_dates:
        empty = pd.DataFrame(columns=["quantity_sold", "is_promotion", "is_regional_holiday",
                                       "is_imputed", "is_stockout", "promotion_uplift_pct",
                                       "holiday_uplift_pct"])
        empty.index = pd.DatetimeIndex([], freq="D", name="date")
        return {"df": empty, "baseline_series": pd.Series(dtype=float),
                "full_series": pd.Series(dtype=float), "transformation": "none"}

    # ── Build promotion + holiday lookup ──────────────────────────────────────
    promo_dates:  dict[str, float] = {}   # date → uplift_pct
    holiday_dates: dict[str, float] = {}

    for p in (promotions or []):
        s = pd.Timestamp(p["start_date"])
        e = pd.Timestamp(p["end_date"])
        uplift = float(p.get("uplift_pct", 0))
        for t in pd.date_range(s, e, freq="D"):
            promo_dates[t.strftime("%Y-%m-%d")] = max(
                promo_dates.get(t.strftime("%Y-%m-%d"), 0), uplift
            )

    for h in (holidays or []):
        d = str(h.get("date") or h.get("holiday_date", ""))
        holiday_dates[d] = float(h.get("uplift_pct", 0))

    # ── Build DataFrame ────────────────────────────────────────────────────────
    rows = []
    for d in all_dates:
        qty     = date_to_qty.get(d)
        gap_cls = classified.get(d, "present")
        is_imp  = 0
        is_so   = 0

        if qty is None:
            qty = 0.0  # filled below by imputation
            is_imp = 1
            if gap_cls == "stockout_gap":
                is_so = 1

        rows.append({
            "date":                  d,
            "quantity_sold_raw":     qty,
            "is_imputed_flag":       is_imp,
            "is_stockout_flag":      is_so,
            "gap_class":             gap_cls,
            "is_promotion":          1 if d in promo_dates else 0,
            "promotion_uplift_pct":  promo_dates.get(d, 0.0),
            "is_regional_holiday":   1 if d in holiday_dates else 0,
            "holiday_uplift_pct":    holiday_dates.get(d, 0.0),
        })

    df = pd.DataFrame(rows)
    df["date"] = pd.to_datetime(df["date"])
    df = df.set_index("date").asfreq("D")
    df["quantity_sold"] = df["quantity_sold_raw"].copy()
    df["is_imputed"]    = df["is_imputed_flag"].astype(int)
    df["is_stockout"]   = df["is_stockout_flag"].astype(int)

    # ── Stockout imputation: 7-day pre-stockout mean ───────────────────────────
    stockout_idx = df.index[df["is_stockout"] == 1]
    if len(stockout_idx) > 0:
        for so_date in stockout_idx:
            window_start = so_date - pd.Timedelta(days=7)
            window_mask  = (
                (df.index >= window_start) & (df.index < so_date) &
                (df["is_imputed"] == 0) & (df["is_stockout"] == 0)
            )
            pre_vals = df.loc[window_mask, "quantity_sold"]
            pre_vals = pre_vals[pre_vals > 0]
            if len(pre_vals) > 0:
                df.loc[so_date, "quantity_sold"] = float(pre_vals.mean())

    # ── Reporting gap imputation: forward-fill ─────────────────────────────────
    rg_mask = df["gap_class"] == "reporting_gap"
    if rg_mask.any():
        df.loc[rg_mask, "quantity_sold"] = np.nan
        df["quantity_sold"] = df["quantity_sold"].ffill()

    # ── True zeros — leave as-is ───────────────────────────────────────────────
    # (already 0.0 from raw; is_imputed=1 signals they were missing)

    # ── Outlier capping (non-promotional days only) ────────────────────────────
    baseline_mask = (df["is_promotion"] == 0) & (df["is_regional_holiday"] == 0)
    baseline_vals = df.loc[baseline_mask, "quantity_sold"]
    if len(baseline_vals) > 0:
        mean_b = float(baseline_vals.mean())
        std_b  = float(baseline_vals.std())
        cap    = mean_b + cap_sigma * std_b
        for d_ts in df.index:
            d_str = d_ts.strftime("%Y-%m-%d")
            if d_str in outliers and df.loc[d_ts, "is_promotion"] == 0:
                df.loc[d_ts, "quantity_sold"] = min(df.loc[d_ts, "quantity_sold"], cap)

    # ── Non-negativity clamp ───────────────────────────────────────────────────
    df["quantity_sold"] = df["quantity_sold"].clip(lower=0.0).fillna(0.0)

    # ── Baseline / full series split ───────────────────────────────────────────
    baseline_series = df.loc[baseline_mask, "quantity_sold"]
    full_series     = df["quantity_sold"]

    # ── Transformation detection (log1p if increasing variance with level) ─────
    transformation = "none"
    if len(baseline_series) >= 60:
        # Check if rolling std correlates with rolling mean (variance instability)
        rm  = baseline_series.rolling(30, min_periods=15).mean()
        rs  = baseline_series.rolling(30, min_periods=15).std()
        valid = rm.notna() & rs.notna() & (rm > 0)
        if valid.sum() >= 10:
            corr = float(np.corrcoef(rm[valid], rs[valid])[0, 1])
            if corr > 0.6:
                transformation = "log1p"

    return {
        "df":             df,
        "baseline_series": baseline_series,
        "full_series":     full_series,
        "transformation":  transformation,
    }
