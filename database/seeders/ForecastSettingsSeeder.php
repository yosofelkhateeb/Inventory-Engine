<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class ForecastSettingsSeeder extends Seeder
{
    /**
     * Default forecasting thresholds per SKU category.
     * See FORECASTING_ENGINE.md § Category Thresholds for rationale.
     */
    private const DEFAULTS = [
        // Equipment — longer lead times, lower sales frequency → more history required
        'forecast.equipment.min_history_days'            => '120',
        'forecast.equipment.seasonality_min_days'        => '365',
        'forecast.equipment.intermittency_threshold'     => '0.30',
        'forecast.equipment.bias_drift_threshold_pct'    => '15',

        // Accessories — faster turnover, less history needed
        'forecast.accessory.min_history_days'          => '90',
        'forecast.accessory.seasonality_min_days'      => '365',
        'forecast.accessory.intermittency_threshold'   => '0.20',
        'forecast.accessory.bias_drift_threshold_pct'  => '15',

        // Bundles — similar to accessories
        'forecast.bundle.min_history_days'             => '90',
        'forecast.bundle.seasonality_min_days'         => '365',
        'forecast.bundle.intermittency_threshold'      => '0.25',
        'forecast.bundle.bias_drift_threshold_pct'     => '15',

        // Global bias drift threshold — triggers re-evaluation when |bias| exceeds this %
        // Used by InventoryEngineService::checkBiasDrift(); per-category keys above for future use
        'forecast.bias_drift_threshold_pct'            => '15',

        // Feedback loop settings — used by recommendation:analyze-feedback command (planned)
        'forecast.model_reeval_interval_days'          => '30',
        'forecast.feedback_drift_threshold_pct'        => '20',

        // Feedback metric thresholds — used by AnalyzeRecommendationFeedbackJob
        'feedback.ignored_rate_threshold'              => '0.30',
        'feedback.superseded_rate_threshold'           => '0.50',
        'feedback.ordered_delta_threshold'             => '5.0',
        'feedback.received_delta_threshold'            => '3.0',

        // ForecastPresenter confidence label thresholds — used by ForecastPresenter::confidenceLabel()
        'forecast.confidence_coverage_target'          => '0.80',
        'forecast.confidence_high_max_width_pct'       => '50',
        'forecast.confidence_medium_max_width_pct'     => '100',

        // Monitoring — stale-feed warning banner threshold (days since last sale)
        'monitoring.sku_staleness_warning_days'        => '7',

        // Promotion uplift defaults — read by RuleBasedLayer as the per-type
        // base term, with rule-coefficient adjustments layered on top. Values
        // are rough industry priors and act as cold-start defaults; once
        // tagged history accrues, the orchestrator routes to Layer 2
        // (nearest-neighbor) and these defaults stop driving day-to-day
        // predictions.
        //   flash     — short-window aggressive discount, biggest spike
        //   clearance — deep discount on slow-movers, sustained spike
        //   seasonal  — moderate planned bump tied to calendar
        //   bundle    — mild attach-rate bump on the bundle SKU
        //   other     — conservative neutral default
        //   generic   — used when promotion_type is null
        'uplift_default.flash'                         => '50',
        'uplift_default.clearance'                     => '40',
        'uplift_default.seasonal'                      => '25',
        'uplift_default.bundle'                        => '20',
        'uplift_default.other'                         => '15',
        'uplift_default.generic'                       => '15',

        // ─── Layered Hybrid Uplift Prediction ───────────────────────────
        // Activation thresholds — the orchestrator (PredictionEngine, Step 4)
        // picks the most informed layer the data supports:
        //   < min_nn_samples       → Layer 1 (rule-based heuristic)
        //   ≥ min_nn_samples       → Layer 2 (nearest-neighbor on Briefs)
        //   ≥ min_ml_samples       → Layer 3 (LightGBM regression)
        // Per-category override lets accessory campaigns activate ML before
        // equipment campaigns when their tagged history accrues faster.
        'uplift.min_nn_samples'                        => '5',
        // Demo dataset seeds ~57 promos but ~48 have computable actualUplift()
        // (early-window promos have partial 30-day baselines). 40 keeps Layer 3
        // firing on this fixture; production tenants can re-tune via the
        // Settings page once real campaign history accrues.
        'uplift.min_ml_samples'                        => '40',
        'uplift.min_ml_samples_per_category'           => '10',

        // Layer 1 — Rule-based heuristic. Predicts uplift from Brief inputs
        // by adding adjustments to a per-type base (uplift_default.<type>
        // above). All values are operator-tunable via the Settings page once
        // tagged history accumulates and the rule weights can be re-fit.
        //
        // Algorithm:
        //   value = base_by_type
        //         + discount_pct_coef * discount_pct
        //         + channel_count_coef * count(channel_mix)
        //         + spend_band_bonus.<band>
        //         + audience_bonus.<audience>
        //   clamped to ≥ 0, rounded to 1 decimal place
        //
        // Confidence band: ±confidence_band_pct% of the point estimate.
        // Layer 1 is intentionally wide — the band narrows as later layers
        // activate.
        'uplift.rule.discount_pct_coef'                => '0.5',
        'uplift.rule.channel_count_coef'               => '3.0',
        'uplift.rule.spend_band_bonus.none'            => '0',
        'uplift.rule.spend_band_bonus.low'             => '5',
        'uplift.rule.spend_band_bonus.mid'             => '15',
        'uplift.rule.spend_band_bonus.high'            => '30',
        'uplift.rule.spend_band_bonus.very_high'       => '50',
        'uplift.rule.audience_bonus.existing_customers' => '0',
        'uplift.rule.audience_bonus.new_acquisition'   => '5',
        'uplift.rule.audience_bonus.both'              => '8',
        'uplift.rule.confidence_band_pct'              => '50',

        // DecisionScorer — multi-factor watch threshold (in days of demand
        // above the reorder point). The threshold is computed per SKU as:
        //
        //   threshold_days = lead_time_mean   * k_lead
        //                  + lead_time_stddev * k_ltv
        //                  + smape * lead_time_mean * k_smape
        //                  + trend_factor * k_trend
        //
        // clipped to [min_days, min(global_ceiling_days, 2*lead_time_mean)].
        //
        // Defaults below are *priors from inventory science literature*
        // intended as cold-start values. Once real client history is
        // connected, the calibration job (Chunk 3) re-fits these from
        // observed (decision, outcome) pairs and writes new values back
        // to system_settings. See docs/INVENTORY_ENGINE.md for sources.
        //
        //   k_lead  = 0.5   — half the lead time as base watch window
        //                     (Silver-Pyke-Peterson, ch. 7 — typical
        //                     review-period buffer for periodic-review
        //                     inventory systems)
        //   k_ltv   = 1.65  — 95% confidence multiplier on lead time
        //                     stddev. Mirrors the z-score used for safety
        //                     stock so watch and safety stock speak the
        //                     same probability language.
        //   k_smape = 0.5   — forecast error contributes half its magnitude
        //                     in days proportional to lead time. Half-weight
        //                     because safety stock partially absorbs demand
        //                     variance (full weight would double-count).
        //   k_trend = 0.0   — disabled at cold start. Trend factor is noisy
        //                     until calibration shows it adds signal beyond
        //                     what's already in the forecast itself.
        //   min_days = 1    — never zero; floor for instant-supply SKUs.
        //   global_ceiling_days = 90 — sanity rail. No SKU should require
        //                     watch when buffer is over 3 months.
        'decision.watch.k_lead'                        => '0.5',
        'decision.watch.k_ltv'                         => '1.65',
        'decision.watch.k_smape'                       => '0.5',
        'decision.watch.k_trend'                       => '0.0',
        'decision.watch.min_days'                      => '1.0',
        'decision.watch.global_ceiling_days'           => '90.0',

        // Calibration drift threshold — if a calibration run produces a
        // score this many percent below the previous run's score, the job
        // emits a `decision_calibration_drift_detected` log warning.
        // Designed for ops monitoring to subscribe to. 20% catches genuine
        // structural shifts without firing on routine RNG-driven movement.
        'decision.watch.calibration_drift_threshold_pct' => '20.0',

        // LeadTimeHandler — observation-driven lead-time derivation.
        //   observation_window_days: trailing window of order arrivals to
        //     consider. 365 captures supplier seasonality (e.g. Chinese
        //     New Year delays, Q4 freight pressure). Smaller windows
        //     adapt faster but lose stability.
        //   min_observations_for_dynamic: minimum sample size before we
        //     trust the observation-derived stat. 5 = enough for a stable
        //     p95 estimate without holding up cold-start adoption.
        // Below the threshold, fall back to supplier-level observations,
        // then to Supplier::avg_lead_time_days. See LeadTimeHandler doc.
        'lead_time.observation_window_days'           => '365',
        'lead_time.min_observations_for_dynamic'      => '5',

        // System-owner email for drift / system-health alerts. Empty
        // string disables email delivery (the SystemAlert row is still
        // persisted — email is just a delivery channel on top of the
        // audit trail). Not seeded with a real address; owners set this
        // themselves via the Settings UI when ready to receive alerts.
        'notifications.system_owner_email'            => '',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            SystemSetting::firstOrCreate(
                ['tenant_id' => 1, 'key' => $key],
                ['value' => $value, 'group' => 'forecasting'],
            );
        }
    }
}
