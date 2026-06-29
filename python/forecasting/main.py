"""
main.py — Python Forecasting Microservice Entry Point (15-stage pipeline).

Called by Laravel's RunForecastJob:
    python python/forecasting/main.py --input /tmp/forecast_input_3_1.json

Runs sequentially:
  Stage 1  Framing (fixed — config only)
  Stage 2  Data audit + preprocessing
  Stage 3  EDA (scheduled/new_sku triggers only)
  Stage 4  Baseline models
  Stage 5  Feature engineering
  Stage 6  Walk-forward CV fold creation
  Stage 7  Candidate model training + CV evaluation
  Stage 8  Metric comparison table
  Stage 9  Diagnostics (top 2 models)
  Stage 10 Optimisation (winner only)
  Stage 11 Prediction intervals
  Stage 12 Final model selection
  Stage 13 Forecast generation (refit on full history)
  Stage 14 Integration (handled by RunForecastJob — outputs stdout JSON)
  Stage 15 Monitoring checks

Exit codes:
    0 — success (or graceful partial success with ets_fallback)
    1 — unhandled exception

Only the final output JSON goes to stdout. All other output goes to logs.
"""

import argparse
import json
import logging
import os
import sys
import time


# ── Logging setup (to reports/ file, not stdout) ──────────────────────────────

def _setup_logging(reports_dir: str, sku_id: int, tenant_id: int) -> None:
    ts = int(time.time())
    log_path = os.path.join(reports_dir, f"run_log_{sku_id}_{tenant_id}_{ts}.txt")
    os.makedirs(reports_dir, exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.FileHandler(log_path, encoding="utf-8")],
    )


def run(input_data: dict) -> dict:
    """
    Core 15-stage pipeline. Returns the output dict (not JSON-encoded).
    Raises only on truly unrecoverable errors.
    """
    # ── Bootstrap ──────────────────────────────────────────────────────────────
    script_dir  = os.path.dirname(os.path.abspath(__file__))
    reports_dir = os.path.join(script_dir, "reports")

    sku_id         = int(input_data["sku_id"])
    tenant_id      = int(input_data["tenant_id"])
    reeval_trigger = str(input_data.get("reeval_trigger", "scheduled"))
    horizon        = int(input_data.get("forecast_horizon", 30))

    _setup_logging(reports_dir, sku_id, tenant_id)
    logger = logging.getLogger(__name__)
    logger.info(f"=== Pipeline start: sku={sku_id} tenant={tenant_id} trigger={reeval_trigger} ===")

    warnings_out: list[str] = []
    sales_history = input_data.get("sales_history", [])
    promotions    = input_data.get("promotions", [])
    holidays      = input_data.get("regional_holidays", [])

    # ── Stage 2a: Data audit ───────────────────────────────────────────────────
    logger.info("Stage 2a: Data audit")
    from pipeline.data_audit import audit
    audit_result = audit(sales_history, sku_id, tenant_id, reports_dir)

    if audit_result["data_quality_score"] < 0.3:
        warnings_out.append(
            f"data_quality_warning: score={audit_result['data_quality_score']:.2f} — "
            f"{audit_result['missing_days']} missing days."
        )

    # Propagate audit-level assertions (trailing-zero run, empty history, etc.)
    for w in audit_result.get("audit_warnings", []):
        warnings_out.append(w)
        logger.warning(f"Audit assertion: {w}")

    # ── Two-tier stale-feed warning ───────────────────────────────────────────
    # The threshold is per-SKU and adaptive (95th percentile of historical
    # inter-arrival gaps with floor/ceiling clipping). A daily seller and a
    # monthly seller have wildly different expected gaps; treating them with
    # one global threshold causes either alarm fatigue or missed alarms.
    #
    # Emit one of:
    #   stale_feed_critical — staleness >= ceiling. Almost certainly broken.
    #                         Replaces (does not co-emit with) stale_feed.
    #   stale_feed          — staleness >= adaptive threshold.
    #                         "Unusual relative to this SKU's own pattern."
    #   (silent)            — within normal selling rhythm; do not warn.
    from pipeline._config import load_config as _load_cfg
    _stale_cfg = _load_cfg()["audit"].get("stale_feed", {}) or {}
    staleness_days   = int(audit_result.get("staleness_days", 0))
    stale_threshold  = int(audit_result.get("stale_feed_threshold_days", 7))
    stale_source     = str(audit_result.get("stale_feed_threshold_source", "fallback"))
    stale_ceiling    = int(_stale_cfg.get("ceiling_days", 60))

    if staleness_days >= stale_ceiling:
        warnings_out.append(
            f"stale_feed_critical: last observation is {staleness_days} day(s) old "
            f"(ceiling {stale_ceiling}d) — likely broken connector or stopped SKU."
        )
    elif staleness_days >= stale_threshold:
        warnings_out.append(
            f"stale_feed: last observation is {staleness_days} day(s) old "
            f"(threshold {stale_threshold}d, source: {stale_source})."
        )

    # ── Stage 2b: Preprocessing ────────────────────────────────────────────────
    logger.info("Stage 2b: Preprocessing")
    from pipeline.preprocessing import preprocess
    prep = preprocess(audit_result, promotions, holidays)
    df             = prep["df"]
    baseline_series = prep["baseline_series"]
    full_series     = prep["full_series"]
    transformation  = prep["transformation"]

    log_transform = (transformation == "log1p")
    if log_transform:
        # Truthful warning: this flag now actually drives SARIMAX fit/predict
        # via _load_model(..., log_transform=True). Other models ignore it.
        warnings_out.append("log1p transformation applied due to increasing variance with level.")

    # ── Stage 1: Classify ──────────────────────────────────────────────────────
    logger.info("Stage 1: Classifier")
    from classifier import classify
    classification = classify(input_data)
    profile    = classification["profile"]

    # ── Stage 3: EDA (conditional) ────────────────────────────────────────────
    eda_result: dict = {}
    run_eda = reeval_trigger in ("scheduled", "new_sku")
    if run_eda and len(baseline_series) >= 14:
        logger.info("Stage 3: EDA")
        from pipeline.eda import run as run_eda_fn
        eda_result = run_eda_fn(baseline_series, sku_id, tenant_id, reports_dir)
        logger.info(
            f"  EDA: trend={eda_result['trend_direction']} "
            f"seasonality={eda_result['seasonality_detected']} "
            f"cv={eda_result['cv_value']:.3f}"
        )
    else:
        logger.info(f"Stage 3: EDA skipped (trigger={reeval_trigger})")

    # ── Short-circuit: insufficient history ────────────────────────────────────
    if classification.get("skip_competition"):
        logger.warning("Insufficient history — ets_fallback without competition")
        warnings_out.append("Insufficient history — ets_fallback assigned without model competition.")
        from models.ets_fallback import ETSFallback
        model = ETSFallback()
        series_list = full_series.tolist()
        if series_list:
            model.fit(series_list)
            demand_rate = model.predict(horizon)
        else:
            demand_rate = 0.0
        from registry import build_output
        # Metrics intentionally None: insufficient history means we never
        # evaluated this model. Writing zeros would falsely advertise
        # perfect accuracy in dashboards and benchmark reports.
        return build_output(
            sku_id, tenant_id, "ets_fallback", demand_rate,
            {"mae": None, "rmse": None, "bias": None, "smape": None},
            profile, reeval_trigger, horizon, warnings_out,
        )

    # ── Stage 6: CV fold creation ──────────────────────────────────────────────
    logger.info("Stage 6: Walk-forward CV fold creation")
    from pipeline.validation import create_folds, get_holdout
    folds = create_folds(full_series)
    # Separate folds for baseline models — use the promo/holiday-free series so
    # naive/MA baselines are not contaminated by promotional spikes during training.
    baseline_folds = create_folds(baseline_series) if len(baseline_series) > 0 else folds
    holdout_arr, holdout_idx = get_holdout(full_series)

    if not folds:
        logger.warning("No CV folds possible — using legacy single split")
        folds = []

    # ── Stage 4: Baselines ────────────────────────────────────────────────────
    logger.info("Stage 4: Baseline evaluation")
    from pipeline.baselines import evaluate as eval_baselines
    baseline_results = eval_baselines(baseline_series, baseline_folds) if baseline_folds else {
        "best_baseline_name": "naive",
        "best_baseline_cv_mae": float("inf"),
        "all_results": {},
    }
    logger.info(f"  Best baseline: {baseline_results['best_baseline_name']} MAE={baseline_results['best_baseline_cv_mae']:.4f}")

    # ── Stage 5: Feature engineering ──────────────────────────────────────────
    logger.info("Stage 5: Feature engineering")
    from pipeline.feature_engineering import build_features
    try:
        feature_df = build_features(df, promotions, holidays, eda_result)
    except Exception as exc:
        logger.warning(f"Feature engineering failed: {exc} — using raw series")
        feature_df = None

    # ── Stage 7/8: Candidate model training + CV evaluation ───────────────────
    logger.info("Stage 7/8: Candidate model training + CV evaluation")
    candidates = classification.get("candidates", ["ets_fallback"])
    series_list = full_series.tolist()
    promo_flags = df["is_promotion"].tolist() if "is_promotion" in df.columns else [0] * len(series_list)
    future_promo = [0] * horizon

    from evaluator import evaluate_on_folds, evaluate_on_holdout, _fit_model, _forecast_model
    candidate_metrics: dict[str, dict] = {}

    # Optional candidates whose absence is informational, not an error.
    # Prophet and LightGBM are explicitly listed as optional in
    # docs/FORECASTING_ENGINE.md — the pipeline runs fine without them.
    # Surfacing "not installed" as a registry warning trains users to
    # ignore real warnings; we log to file but skip the UI-facing emission.
    OPTIONAL_CANDIDATES = {"prophet", "lightgbm"}
    for name in candidates:
        model = _load_model(name, log_transform=log_transform)
        if model is None:
            if name in OPTIONAL_CANDIDATES:
                logger.info(f"  {name} not installed — skipped (optional candidate)")
            else:
                warnings_out.append(f"{name} is not installed — skipped.")
                logger.warning(f"  {name} skipped (not installed)")
            continue

        logger.info(f"  Evaluating {name}...")
        try:
            exog_col = "is_promotion" if name == "sarimax" else None
            cv_metrics = evaluate_on_folds(name, model, full_series, folds, feature_df, exog_col) if folds else None

            if cv_metrics is None:
                # Fallback to legacy split
                from evaluator import evaluate_model
                legacy = evaluate_model(name, _load_model(name, log_transform=log_transform), series_list,
                                        exog=promo_flags if name == "sarimax" else None,
                                        future_exog=future_promo if name == "sarimax" else None)
                if legacy:
                    cv_metrics = {
                        "cv_mae": legacy["mae"], "cv_rmse": legacy["rmse"],
                        "cv_bias": legacy["bias"], "cv_smape": legacy["smape"],
                        "n_folds": 1, "runtime_sec": 0.0,
                    }

            if cv_metrics:
                # Holdout evaluation
                exog_train = promo_flags[:len(series_list) - len(holdout_arr)] if name == "sarimax" else None
                exog_hold  = promo_flags[-len(holdout_arr):] if name == "sarimax" else None
                holdout_imp = None
                if feature_df is not None and "is_imputed" in feature_df.columns:
                    holdout_imp = feature_df["is_imputed"].values[-len(holdout_arr):]

                holdout_model = _load_model(name, log_transform=log_transform)
                test_metrics = evaluate_on_holdout(
                    name, holdout_model,
                    series_list[:-len(holdout_arr)] if len(holdout_arr) > 0 else series_list,
                    holdout_arr, exog_train, exog_hold, holdout_imp,
                )
                candidate_metrics[name] = {**cv_metrics, **test_metrics}
                logger.info(f"    {name}: CV MAE={cv_metrics['cv_mae']:.4f} test MAE={test_metrics.get('test_mae', '?')}")
        except Exception as exc:
            warnings_out.append(f"{name} failed during evaluation — skipped.")
            logger.warning(f"  {name} failed: {exc}")
            continue

    # Ensure ets_fallback is always a competitor
    if "ets_fallback" not in candidate_metrics:
        from models.ets_fallback import ETSFallback
        from evaluator import evaluate_model as legacy_eval
        fb = ETSFallback()
        fb_cv = evaluate_on_folds("ets_fallback", fb, full_series, folds) if folds else None
        if fb_cv is None:
            fb_legacy = legacy_eval("ets_fallback", fb, series_list)
            if fb_legacy:
                fb_cv = {"cv_mae": fb_legacy["mae"], "cv_rmse": fb_legacy["rmse"],
                         "cv_bias": fb_legacy["bias"], "cv_smape": fb_legacy["smape"],
                         "n_folds": 1, "runtime_sec": 0.0}
        if fb_cv:
            fb_test = evaluate_on_holdout("ets_fallback", ETSFallback(), series_list[:-len(holdout_arr)] if holdout_arr.size > 0 else series_list, holdout_arr)
            candidate_metrics["ets_fallback"] = {**fb_cv, **fb_test}

    if not candidate_metrics:
        logger.error("All models failed — using ets_fallback without evaluation")
        warnings_out.append("All candidate models failed — ets_fallback assigned without evaluation.")
        from models.ets_fallback import ETSFallback
        from registry import build_output
        fb = ETSFallback()
        fb.fit(series_list)
        demand_rate = fb.predict(horizon)
        # Metrics None: every candidate raised, so we have no evaluation to
        # report. Storing zeros would mask the failure in dashboards.
        return build_output(
            sku_id, tenant_id, "ets_fallback", demand_rate,
            {"mae": None, "rmse": None, "bias": None, "smape": None},
            profile, reeval_trigger, horizon, warnings_out,
        )

    # ── Stage 9: Diagnostics (top 2 models by CV MAE) ────────────────────────
    logger.info("Stage 9: Diagnostics")
    from pipeline.diagnostics import run as run_diagnostics
    diagnostic_results: dict[str, dict] = {}
    top2 = sorted(candidate_metrics.items(), key=lambda x: x[1].get("cv_mae", float("inf")))[:2]

    for name, _ in top2:
        try:
            diag_model = _load_model(name, log_transform=log_transform)
            exog_tr = promo_flags[:-len(holdout_arr)] if name == "sarimax" and holdout_arr.size > 0 else None
            _fit_model(name, diag_model, series_list[:-len(holdout_arr)] if holdout_arr.size > 0 else series_list, exog_tr)
            exog_fut = promo_flags[-len(holdout_arr):] if name == "sarimax" else None
            fc = _forecast_model(name, diag_model, len(holdout_arr), exog_fut)
            diag = run_diagnostics(name, holdout_arr, fc, df, sku_id, tenant_id, reports_dir)
            diagnostic_results[name] = diag
            logger.info(f"  {name} diagnostics: autocorrelation_concern={diag.get('residual_autocorrelation_concern', False)}")
        except Exception as exc:
            logger.warning(f"  Diagnostics failed for {name}: {exc}")

    # ── Stage 12: Final model selection ────────────────────────────────────────
    logger.info("Stage 12: Model selection")
    from models.selection import select
    selection_result = select(
        candidate_metrics, baseline_results, diagnostic_results,
        sku_id, tenant_id, reports_dir,
    )
    winner_name = selection_result["selected_model"]
    logger.info(f"  Winner: {winner_name}")

    # ── Stage 10: Optimisation (winner only) ──────────────────────────────────
    logger.info("Stage 10: Optimisation")
    winner_model = _load_model(winner_name, log_transform=log_transform)
    promo_for_fit = promo_flags if winner_name == "sarimax" else None
    try:
        if folds and hasattr(winner_model, "tune"):
            if winner_name in ("holt_winters", "ets_fallback"):
                winner_model.tune(series_list, folds)
            elif winner_name == "lightgbm":
                winner_model.tune(series_list, folds, promo_flags)
    except Exception as exc:
        logger.warning(f"Tuning failed for {winner_name}: {exc}")

    # ── Stage 13: Final forecast generation (refit on full history) ───────────
    logger.info("Stage 13: Forecast generation (full history refit)")
    try:
        _fit_model(winner_name, winner_model, series_list, promo_flags if winner_name == "sarimax" else None)
        fc_series = _forecast_model(winner_name, winner_model, horizon, [0] * horizon if winner_name == "sarimax" else None)

        # Promotional uplift adjustment for non-SARIMAX models
        if winner_name != "sarimax":
            fc_series = _apply_promo_uplift(fc_series, promotions, horizon)

        demand_rate = float(max(0.0, sum(fc_series) / len(fc_series))) if fc_series else 0.0
    except Exception as exc:
        logger.error(f"Forecast generation failed: {exc}")
        demand_rate = float(sum(series_list[-30:]) / 30) if series_list else 0.0
        fc_series = [demand_rate] * horizon
        warnings_out.append(f"Forecast generation failed for {winner_name}: {exc}")

    # ── Stage 11: Prediction intervals ────────────────────────────────────────
    logger.info("Stage 11: Prediction intervals")
    from pipeline.intervals import compute as compute_intervals
    intervals_result = {}
    try:
        intervals_result = compute_intervals(
            winner_name, winner_model, series_list, holdout_arr, horizon,
            exog_train=promo_flags if winner_name == "sarimax" else None,
            exog_future=[0] * horizon if winner_name == "sarimax" else None,
        )
    except Exception as exc:
        logger.warning(f"Interval computation failed: {exc}")
        std_r = float(__import__("numpy").std(fc_series)) if fc_series else 1.0
        intervals_result = {
            "interval_lower": round(max(0.0, demand_rate - 1.96 * std_r), 4),
            "interval_upper": round(demand_rate + 1.96 * std_r, 4),
            "interval_confidence": 0.95,
            "interval_empirical_coverage": None,
        }

    # ── Stage 15: Monitoring checks ────────────────────────────────────────────
    logger.info("Stage 15: Monitoring checks")
    from pipeline.monitoring import check as monitoring_check
    try:
        monitoring = monitoring_check(
            full_series_values=series_list,
            empirical_coverage=intervals_result.get("interval_empirical_coverage"),
        )
        warnings_out.extend(monitoring.get("warnings", []))
    except Exception as exc:
        logger.warning(f"Monitoring check failed: {exc}")

    # Warn on RMSE >> MAE
    winner_m = candidate_metrics.get(winner_name, {})
    if winner_m.get("cv_rmse", 0) > winner_m.get("cv_mae", 0) * 2:
        warnings_out.append(
            f"{winner_name}: RMSE ({winner_m['cv_rmse']:.2f}) >> MAE "
            f"({winner_m['cv_mae']:.2f}) — occasional large misses detected."
        )

    # Sanity ceiling — loud warning when the winning model's sMAPE is
    # implausibly high. This is the guardrail that would have caught the
    # 100% WMAPE silent bugs we fixed in 2026-04.
    from pipeline._config import load_config as _load_cfg
    _cfg = _load_cfg()
    sanity_ceiling = float(_cfg["evaluation"].get("wmape_sanity_ceiling_pct", 80.0))
    winner_smape = float(winner_m.get("test_smape", winner_m.get("cv_smape", 0)))
    if winner_smape > sanity_ceiling:
        warnings_out.append(
            f"sanity_ceiling_breach: {winner_name} sMAPE={winner_smape:.1f}% exceeds "
            f"{sanity_ceiling:.0f}% ceiling — forecast may be unreliable; review input data."
        )
        logger.error(
            f"Sanity ceiling breach: sku={sku_id} model={winner_name} sMAPE={winner_smape:.1f}%"
        )

    # ── Build output ───────────────────────────────────────────────────────────
    from registry import build_output
    hyperparameters = getattr(winner_model, "_hyperparameters", {})
    output = build_output(
        sku_id, tenant_id, winner_name, demand_rate,
        winner_m, profile, reeval_trigger, horizon, warnings_out,
        intervals=intervals_result,
        selection=selection_result,
        hyperparameters=hyperparameters,
        transformation=transformation,
    )

    logger.info(f"=== Pipeline complete: demand_rate={demand_rate:.4f} model={winner_name} ===")
    return output


# ── Model loader ───────────────────────────────────────────────────────────────

def _load_model(name: str, log_transform: bool = False):
    """Instantiate a fresh model by name. Returns None if unavailable.

    `log_transform` is honoured by SARIMAX (variance-stabilising fit on
    log1p data, expm1 on output). Other models ignore it for now —
    multiplicative seasonality in HW handles the same case differently,
    and the other candidates are less affected by promo-spike heteroscedasticity.
    """
    if name == "ets_fallback":
        from models.ets_fallback import ETSFallback
        return ETSFallback()
    if name == "holt_winters":
        from models.holt_winters import HoltWinters
        return HoltWinters()
    if name == "croston":
        from models.croston import Croston
        return Croston()
    if name == "sarimax":
        from models.sarimax import SARIMAX
        return SARIMAX(log_transform=log_transform)
    if name == "lightgbm":
        from models.lightgbm_model import LightGBMModel
        return LightGBMModel()
    if name == "prophet":
        try:
            from models.prophet_model import NotAvailable, ProphetModel
            return None if NotAvailable else ProphetModel()
        except Exception:
            return None
    return None


def _apply_promo_uplift(fc_series: list[float], promotions: list[dict], horizon: int) -> list[float]:
    """Apply promotional uplift to forecast days where a promotion is active."""
    import datetime
    today = datetime.date.today()

    promo_uplift: dict[datetime.date, float] = {}
    for p in (promotions or []):
        try:
            s = datetime.date.fromisoformat(str(p["start_date"]))
            e = datetime.date.fromisoformat(str(p["end_date"]))
            uplift = float(p.get("uplift_pct", 0))
            d = s
            while d <= e:
                promo_uplift[d] = max(promo_uplift.get(d, 0), uplift)
                d += datetime.timedelta(days=1)
        except Exception:
            pass

    result = []
    for i, v in enumerate(fc_series[:horizon]):
        day = today + datetime.timedelta(days=i)
        uplift = promo_uplift.get(day, 0.0)
        result.append(max(0.0, v * (1 + uplift / 100)))
    return result


# ── Entry point ────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Procurement forecasting microservice")
    parser.add_argument("--input", required=True, help="Path to input JSON file")
    args = parser.parse_args()

    try:
        with open(args.input, "r", encoding="utf-8") as f:
            input_data = json.load(f)
    except Exception as exc:
        print(json.dumps({"error": f"Failed to read input file: {exc}"}))
        sys.exit(1)

    try:
        result = run(input_data)
        print(json.dumps(result))
        sys.exit(0)
    except Exception as exc:
        import traceback
        traceback.print_exc(file=sys.stderr)
        print(json.dumps({"error": str(exc)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
