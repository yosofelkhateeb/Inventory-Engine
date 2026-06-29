"""
Stage 2: Data Audit

Validates raw sales history before preprocessing. Classifies missing periods,
flags outliers, checks for negatives and duplicates.

Writes: reports/audit_{sku_id}_{tenant_id}.json
"""
import json
import os
from datetime import datetime, timedelta

import numpy as np

from pipeline._config import load_config


def audit(
    sales_records: list[dict],
    sku_id: int,
    tenant_id: int,
    reports_dir: str,
) -> dict:
    """
    Audit raw sales records.

    Returns an audit dict with keys:
        all_dates           : list[str] — complete daily index
        date_to_qty         : dict[str, float] — date → cleaned quantity
        missing_dates       : list[str]
        classified_gaps     : dict[str, str]  — date → 'true_zero' / 'stockout_gap' / 'reporting_gap'
        outlier_candidates  : list[str]
        negative_dates      : list[str]
        duplicate_dates     : list[str]
        data_quality_score  : float (0–1)
        mean_quantity       : float
        std_quantity        : float
    """
    cfg   = load_config()
    sigma = float(cfg["audit"]["outlier_sigma_threshold"])
    gap_n = int(cfg["audit"]["reporting_gap_consecutive_days"])
    trailing_zero_limit = int(cfg["audit"].get("max_trailing_zero_run_days", 3))
    stale_cfg = cfg["audit"].get("stale_feed", {}) or {}

    if not sales_records:
        return _empty_audit(sku_id, tenant_id, reports_dir)

    # ── 1. Parse + sort ────────────────────────────────────────────────────────
    raw: list[tuple[str, float]] = []
    for r in sales_records:
        date_str = str(r.get("date") or r.get("sale_date", ""))
        qty = float(r.get("quantity_sold", 0))
        raw.append((date_str, qty))
    raw.sort(key=lambda x: x[0])

    # ── 2. Duplicate date check (aggregate by summing) ─────────────────────────
    date_to_qty: dict[str, float] = {}
    duplicate_dates: list[str] = []
    for date_str, qty in raw:
        if date_str in date_to_qty:
            duplicate_dates.append(date_str)
            date_to_qty[date_str] += qty
        else:
            date_to_qty[date_str] = qty

    # ── 3. Complete daily index ────────────────────────────────────────────────
    # Span is bounded by the actual data's first/last dates. Previously we
    # extended `last_date = max(last_date, today)` to detect reporting gaps
    # at the tail, but that silently injects zero-filled days between the
    # data's true last day and "now". For a SKU whose last reported sale was
    # 5 days ago, five trailing zeros distort the level/seasonal indices,
    # halving forecasts for high-volume SKUs. Trailing gaps are the future,
    # not zero-sale days — data freshness is surfaced via `staleness_days`
    # below, not by injecting zeros into the series.
    try:
        first_date = datetime.strptime(raw[0][0], "%Y-%m-%d")
        last_date  = datetime.strptime(raw[-1][0], "%Y-%m-%d")
    except ValueError:
        return _empty_audit(sku_id, tenant_id, reports_dir)

    today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    staleness_days = max(0, (today - last_date).days)

    all_dates: list[str] = []
    d = first_date
    while d <= last_date:
        all_dates.append(d.strftime("%Y-%m-%d"))
        d += timedelta(days=1)

    # ── 4. Identify missing dates ──────────────────────────────────────────────
    date_set = set(date_to_qty.keys())
    missing_dates = [d for d in all_dates if d not in date_set]

    # ── 5. Negative quantity check ─────────────────────────────────────────────
    negative_dates: list[str] = []
    for d in list(date_to_qty.keys()):
        if date_to_qty[d] < 0:
            negative_dates.append(d)
            del date_to_qty[d]
            if d not in missing_dates:
                missing_dates.append(d)

    # ── 6. Outlier detection (mean ± N·σ on present quantities) ───────────────
    present_vals = list(date_to_qty.values())
    mean_q = float(np.mean(present_vals)) if present_vals else 0.0
    std_q  = float(np.std(present_vals))  if present_vals else 0.0

    outlier_candidates: list[str] = []
    if std_q > 0:
        for d, q in date_to_qty.items():
            if abs(q - mean_q) > sigma * std_q:
                outlier_candidates.append(d)

    # ── 7. Classify missing periods ────────────────────────────────────────────
    # Find consecutive runs of missing dates; long runs = reporting_gap
    classified_gaps: dict[str, str] = {}
    if missing_dates:
        missing_set = set(missing_dates)
        visited: set[str] = set()

        for d in sorted(missing_dates):
            if d in visited:
                continue
            # Find run length
            run = [d]
            cur = datetime.strptime(d, "%Y-%m-%d")
            while True:
                nxt = (cur + timedelta(days=1)).strftime("%Y-%m-%d")
                if nxt in missing_set:
                    run.append(nxt)
                    cur += timedelta(days=1)
                    visited.add(nxt)
                else:
                    break
            visited.add(d)

            gap_type = "reporting_gap" if len(run) >= gap_n else "true_zero"
            for rd in run:
                classified_gaps[rd] = gap_type

    # ── 8. Trailing-zero run detection ─────────────────────────────────────────
    # Count consecutive zero-quantity days at the tail of the series. Missing
    # dates count as zero since they imputed as zero downstream. Surfacing this
    # catches stopped-selling SKUs and stale feeds before they silently depress
    # seasonal/level estimates.
    trailing_zero_run_days = 0
    for date_str in reversed(all_dates):
        qty = date_to_qty.get(date_str, 0.0)
        if qty <= 0:
            trailing_zero_run_days += 1
        else:
            break

    # ── 8b. Adaptive stale-feed threshold ─────────────────────────────────────
    # Derive a threshold from this SKU's own selling rhythm rather than a
    # global constant. A daily seller and a monthly seller should have wildly
    # different "this is stale" thresholds; using one number for both either
    # over-alerts on slow movers or under-alerts on fast ones.
    #
    # Statistic: 95th percentile of inter-arrival gaps (days between consecutive
    # non-zero sale days) over a trailing window. Robust to right-skewed
    # distributions typical of retail demand. Falls back to a fixed default
    # when the sample is too small to estimate the percentile reliably.
    stale_threshold, stale_source = _compute_stale_threshold(
        date_to_qty, last_date, stale_cfg,
    )

    # ── 9. Data quality score ─────────────────────────────────────────────────
    total_days = len(all_dates)
    missing_ratio   = len(missing_dates) / max(total_days, 1)
    negative_ratio  = len(negative_dates) / max(len(raw), 1)
    outlier_ratio   = len(outlier_candidates) / max(len(present_vals), 1)
    quality_score   = max(0.0, 1.0 - (missing_ratio * 0.5 + negative_ratio * 0.3 + outlier_ratio * 0.2))

    audit_warnings: list[str] = []
    if trailing_zero_run_days > trailing_zero_limit:
        audit_warnings.append(
            f"trailing_zero_run: last {trailing_zero_run_days} days are all zero "
            f"(threshold {trailing_zero_limit}) — SKU may have stopped selling or "
            f"the feed is stale."
        )

    result = {
        "sku_id":                 sku_id,
        "tenant_id":              tenant_id,
        "total_days":             total_days,
        "present_days":           len(date_to_qty),
        "missing_days":           len(missing_dates),
        "missing_dates":          sorted(missing_dates),
        "negative_dates":         negative_dates,
        "duplicate_dates":        sorted(set(duplicate_dates)),
        "outlier_candidates":     sorted(outlier_candidates),
        "classified_gaps":        classified_gaps,
        "date_to_qty":            date_to_qty,
        "all_dates":              all_dates,
        "data_quality_score":     round(quality_score, 3),
        "mean_quantity":          round(mean_q, 4),
        "std_quantity":           round(std_q, 4),
        "staleness_days":         staleness_days,
        "trailing_zero_run_days": trailing_zero_run_days,
        "stale_feed_threshold_days": stale_threshold,
        "stale_feed_threshold_source": stale_source,
        "audit_warnings":         audit_warnings,
    }

    _write_report(result, sku_id, tenant_id, reports_dir)
    return result


# ── Helpers ────────────────────────────────────────────────────────────────────

def _compute_stale_threshold(
    date_to_qty: dict[str, float],
    last_date: datetime,
    stale_cfg: dict,
) -> tuple[int, str]:
    """
    Compute the adaptive stale-feed threshold for this SKU.

    Returns (threshold_days, source) where source identifies how the value
    was derived: "adaptive_p95", "fallback_insufficient_history", or
    "clipped_floor"/"clipped_ceiling" if the adaptive value was bounded.
    """
    window_days  = int(stale_cfg.get("history_window_days", 180))
    min_samples  = int(stale_cfg.get("min_samples",         15))
    fallback     = int(stale_cfg.get("fallback_days",       7))
    floor_days   = int(stale_cfg.get("floor_days",          3))
    ceiling_days = int(stale_cfg.get("ceiling_days",        60))
    statistic    = str(stale_cfg.get("statistic",           "p95"))

    # Active days in the rolling window (last `window_days` ending at last_date).
    window_start = last_date - timedelta(days=window_days)
    active_dates: list[datetime] = []
    for date_str, qty in date_to_qty.items():
        if qty <= 0:
            continue
        try:
            d = datetime.strptime(date_str, "%Y-%m-%d")
        except ValueError:
            continue
        if window_start <= d <= last_date:
            active_dates.append(d)

    active_dates.sort()
    gaps = [
        (active_dates[i + 1] - active_dates[i]).days
        for i in range(len(active_dates) - 1)
    ]

    if len(gaps) < min_samples:
        return _clip(fallback, floor_days, ceiling_days, "fallback_insufficient_history")

    if statistic == "mean_plus_2sigma":
        value = float(np.mean(gaps) + 2 * np.std(gaps))
        source = "adaptive_mean_plus_2sigma"
    else:  # p95 default
        value = float(np.percentile(gaps, 95))
        source = "adaptive_p95"

    return _clip(int(round(value)), floor_days, ceiling_days, source)


def _clip(value: int, floor_days: int, ceiling_days: int, source: str) -> tuple[int, str]:
    if value < floor_days:
        return floor_days, f"{source}_clipped_floor"
    if value > ceiling_days:
        return ceiling_days, f"{source}_clipped_ceiling"
    return value, source


def _empty_audit(sku_id: int, tenant_id: int, reports_dir: str) -> dict:
    result = {
        "sku_id": sku_id, "tenant_id": tenant_id,
        "total_days": 0, "present_days": 0, "missing_days": 0,
        "missing_dates": [], "negative_dates": [], "duplicate_dates": [],
        "outlier_candidates": [], "classified_gaps": {}, "date_to_qty": {},
        "all_dates": [], "data_quality_score": 0.0,
        "mean_quantity": 0.0, "std_quantity": 0.0,
        "staleness_days": 0, "trailing_zero_run_days": 0,
        "stale_feed_threshold_days": 7,
        "stale_feed_threshold_source": "fallback_empty",
        "audit_warnings": ["empty_sales_history: no usable records received."],
    }
    _write_report(result, sku_id, tenant_id, reports_dir)
    return result


def _write_report(result: dict, sku_id: int, tenant_id: int, reports_dir: str) -> None:
    os.makedirs(reports_dir, exist_ok=True)
    # Exclude large internal dicts from the serialised report
    report = {k: v for k, v in result.items() if k not in ("date_to_qty", "all_dates")}
    path = os.path.join(reports_dir, f"audit_{sku_id}_{tenant_id}.json")
    with open(path, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, default=str)
