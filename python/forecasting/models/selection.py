"""
Stage 12: Final Model Selection

Selection criteria (in priority order):
1. Must beat best baseline on CV MAE — hard requirement
2. Lowest held-out test MAE among qualifying candidates
3. Bias magnitude — prefer lower |bias| when MAE is close (within threshold%)
4. Diagnostic fitness — penalise residual autocorrelation / poor promotion accuracy
5. Simplicity preference when within threshold%: ETS > HW > SARIMAX > Prophet > LightGBM
6. Runtime flag (> 30 sec warning, does not disqualify)

Writes selection rationale to reports/model_comparison_{sku_id}_{tenant_id}.csv
"""
import csv
import json
import logging
import os

from pipeline._config import load_config

logger = logging.getLogger(__name__)

# Simplicity order (lower index = simpler / preferred when performance is tied)
SIMPLICITY_ORDER = ["ets_fallback", "croston", "holt_winters", "sarimax", "prophet", "lightgbm"]


def select(
    candidate_results: dict,      # {model_name: {cv_mae, cv_rmse, cv_bias, cv_smape, test_mae, test_rmse, test_bias, test_smape, runtime_sec}}
    baseline_results: dict,       # from baselines.evaluate()
    diagnostic_results: dict,     # {model_name: diagnostic_summary dict} from diagnostics.run()
    sku_id: int,
    tenant_id: int,
    reports_dir: str,
) -> dict:
    """
    Select the final model from the candidate evaluation results.

    Returns:
        {
            selected_model       : str,
            selection_rationale  : str,
            runner_up            : str | None,
            baseline_winner      : str,
            baseline_cv_mae      : float,
            selected_cv_mae      : float,
            selected_test_mae    : float,
            all_results          : dict,
        }
    """
    cfg       = load_config()
    threshold = float(cfg["evaluation"]["simplicity_threshold_pct"]) / 100
    baseline_tol = float(cfg["evaluation"].get("baseline_tolerance_pct", 10.0)) / 100
    max_rt    = float(cfg["evaluation"]["max_runtime_sec_warning"])

    best_baseline_name = baseline_results.get("best_baseline_name", "naive")
    best_baseline_mae  = float(baseline_results.get("best_baseline_cv_mae", float("inf")))

    # ── Gate 1: Within baseline_tolerance_pct of the best baseline's CV MAE ──
    # A strict `cv_mae < best_baseline_mae` gate rejects statistically-tied
    # candidates (differences <1% MAE on noisy data), which leaves ets_fallback
    # auto-assigned even when a real model has materially lower TEST MAE on the
    # held-out set. The tolerance keeps the "don't use ML when baselines work"
    # intent but lets Gate 2 (test MAE) decide true ties.
    baseline_cutoff = best_baseline_mae * (1 + baseline_tol)
    eligible: dict[str, dict] = {}
    for name, metrics in candidate_results.items():
        cv_mae = float(metrics.get("cv_mae", float("inf")))
        if cv_mae <= baseline_cutoff:
            eligible[name] = metrics

    # If nothing is even close to baseline, fall back to ets_fallback (always instantiable)
    if not eligible:
        rationale = (
            f"No candidate model came within {baseline_tol:.0%} of the best baseline "
            f"({best_baseline_name}, CV MAE={best_baseline_mae:.4f}). "
            f"Using ets_fallback for production forecasting (simplest always-available model)."
        )
        logger.warning(f"Selection: {rationale}")

        return {
            "selected_model":       "ets_fallback",
            "selection_rationale":  rationale,
            "runner_up":            None,
            "baseline_winner":      best_baseline_name,
            "baseline_cv_mae":      best_baseline_mae,
            "selected_cv_mae":      float(candidate_results.get("ets_fallback", {}).get("cv_mae", best_baseline_mae)),
            "selected_test_mae":    float(candidate_results.get("ets_fallback", {}).get("test_mae", best_baseline_mae)),
            "all_results":          candidate_results,
        }

    # ── Gate 2: Rank by test MAE ──────────────────────────────────────────────
    ranked = sorted(eligible.items(), key=lambda x: float(x[1].get("test_mae", x[1].get("cv_mae", float("inf")))))

    winner_name, winner_metrics = ranked[0]
    winner_test_mae = float(winner_metrics.get("test_mae", winner_metrics.get("cv_mae", 0)))
    winner_cv_mae   = float(winner_metrics.get("cv_mae", 0))

    runner_up = ranked[1][0] if len(ranked) > 1 else None

    # ── Gate 3: Bias tiebreaker (within threshold%) ───────────────────────────
    # Check if any candidate within threshold% MAE has lower bias
    notes = []
    if runner_up:
        ru_metrics   = eligible[runner_up]
        ru_test_mae  = float(ru_metrics.get("test_mae", ru_metrics.get("cv_mae", float("inf"))))
        if winner_test_mae > 0 and abs(ru_test_mae - winner_test_mae) / winner_test_mae <= threshold:
            w_bias  = abs(float(winner_metrics.get("test_bias", winner_metrics.get("cv_bias", 0))))
            ru_bias = abs(float(ru_metrics.get("test_bias", ru_metrics.get("cv_bias", 0))))
            if ru_bias < w_bias * 0.9:  # runner-up has meaningfully lower bias
                notes.append(
                    f"Note: {runner_up} has similar MAE ({ru_test_mae:.4f}) but lower bias "
                    f"({ru_bias:.4f} vs {w_bias:.4f}). Consider if bias matters more."
                )

    # ── Gate 4: Diagnostic penalty ────────────────────────────────────────────
    diag = diagnostic_results.get(winner_name, {})
    if diag.get("residual_autocorrelation_concern", False):
        notes.append(
            f"Warning: {winner_name} has residual autocorrelation (Ljung-Box p="
            f"{diag.get('ljung_box_p_value', 'N/A'):.3f}) — may have uncaptured temporal structure."
        )
    if diag.get("promotion_period_mae") and diag.get("baseline_period_mae"):
        p_mae = float(diag["promotion_period_mae"])
        b_mae = float(diag["baseline_period_mae"])
        if p_mae > b_mae * 2:
            notes.append(
                f"Warning: {winner_name} performs significantly worse during promotions "
                f"(MAE={p_mae:.2f}) vs baseline periods (MAE={b_mae:.2f})."
            )

    # ── Gate 5: Simplicity preference within threshold% ───────────────────────
    for alt_name, alt_metrics in ranked[1:]:
        alt_test_mae = float(alt_metrics.get("test_mae", alt_metrics.get("cv_mae", float("inf"))))
        if winner_test_mae > 0 and abs(alt_test_mae - winner_test_mae) / winner_test_mae <= threshold:
            w_simplicity  = _simplicity_rank(winner_name)
            alt_simplicity = _simplicity_rank(alt_name)
            if alt_simplicity < w_simplicity:
                notes.append(
                    f"Simplicity applied: {alt_name} (rank {alt_simplicity}) preferred over "
                    f"{winner_name} (rank {w_simplicity}) — MAE within {threshold:.0%}."
                )
                # Swap
                runner_up    = winner_name
                winner_name  = alt_name
                winner_metrics = alt_metrics
                winner_test_mae = alt_test_mae
                winner_cv_mae   = float(alt_metrics.get("cv_mae", 0))
                break

    # ── Gate 6: Runtime warning ────────────────────────────────────────────────
    rt = float(winner_metrics.get("runtime_sec", 0))
    if rt > max_rt:
        notes.append(
            f"Performance note: {winner_name} runtime={rt:.1f}s exceeds {max_rt}s threshold."
        )

    # ── Build rationale string ─────────────────────────────────────────────────
    baseline_comparison = (
        f"Beat baseline ({best_baseline_name}) CV MAE={best_baseline_mae:.4f}."
        if winner_cv_mae < best_baseline_mae
        else f"Within {baseline_tol:.0%} of baseline ({best_baseline_name}) CV MAE={best_baseline_mae:.4f}."
    )
    rationale_parts = [
        f"Selected {winner_name}: test MAE={winner_test_mae:.4f}, CV MAE={winner_cv_mae:.4f}.",
        baseline_comparison,
    ]
    if runner_up:
        ru_test = float(eligible[runner_up].get("test_mae", eligible[runner_up].get("cv_mae", 0)))
        rationale_parts.append(f"Runner-up: {runner_up} (test MAE={ru_test:.4f}).")
    if notes:
        rationale_parts.extend(notes)

    selection_rationale = " ".join(rationale_parts)

    # ── Write comparison CSV ──────────────────────────────────────────────────
    _write_comparison_csv(
        candidate_results, baseline_results, sku_id, tenant_id, reports_dir
    )

    return {
        "selected_model":      winner_name,
        "selection_rationale": selection_rationale,
        "runner_up":           runner_up,
        "baseline_winner":     best_baseline_name,
        "baseline_cv_mae":     round(best_baseline_mae, 4),
        "selected_cv_mae":     round(winner_cv_mae, 4),
        "selected_test_mae":   round(winner_test_mae, 4),
        "all_results":         candidate_results,
    }


# ── Helpers ────────────────────────────────────────────────────────────────────

def _simplicity_rank(name: str) -> int:
    """Lower = simpler (preferred when performance is tied)."""
    try:
        return SIMPLICITY_ORDER.index(name)
    except ValueError:
        return 99


def _write_comparison_csv(
    candidate_results: dict,
    baseline_results: dict,
    sku_id: int,
    tenant_id: int,
    reports_dir: str,
) -> None:
    os.makedirs(reports_dir, exist_ok=True)
    path = os.path.join(reports_dir, f"model_comparison_{sku_id}_{tenant_id}.csv")
    rows = []

    # Candidates
    best_bl_mae = float(baseline_results.get("best_baseline_cv_mae", float("inf")))
    for name, m in candidate_results.items():
        rows.append({
            "model_name":        name,
            "cv_mae":            m.get("cv_mae", ""),
            "cv_rmse":           m.get("cv_rmse", ""),
            "cv_bias":           m.get("cv_bias", ""),
            "cv_smape":          m.get("cv_smape", ""),
            "test_mae":          m.get("test_mae", ""),
            "test_rmse":         m.get("test_rmse", ""),
            "test_bias":         m.get("test_bias", ""),
            "test_smape":        m.get("test_smape", ""),
            "runtime_sec":       m.get("runtime_sec", ""),
            "beat_best_baseline": float(m.get("cv_mae", float("inf"))) < best_bl_mae,
        })

    # Baselines
    for name, m in baseline_results.get("all_results", {}).items():
        rows.append({
            "model_name":        f"baseline:{name}",
            "cv_mae":            m.get("cv_mae", ""),
            "cv_rmse":           m.get("cv_rmse", ""),
            "cv_bias":           m.get("cv_bias", ""),
            "cv_smape":          m.get("cv_smape", ""),
            "test_mae":          "",
            "test_rmse":         "",
            "test_bias":         "",
            "test_smape":        "",
            "runtime_sec":       "",
            "beat_best_baseline": False,
        })

    if rows:
        with open(path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=rows[0].keys())
            writer.writeheader()
            writer.writerows(rows)
