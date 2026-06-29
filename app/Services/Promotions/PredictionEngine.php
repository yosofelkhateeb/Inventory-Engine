<?php

namespace App\Services\Promotions;

use App\Models\Promotion;
use App\Models\SystemSetting;
use App\Services\Promotions\Layers\MlLayer;
use App\Services\Promotions\Layers\NearestNeighborLayer;
use App\Services\Promotions\Layers\RuleBasedLayer;
use App\Support\TenantContext;
use Carbon\Carbon;

/**
 * Layered Hybrid Uplift Prediction Engine — picks the most informed
 * layer the available data supports, falling back when a layer can't
 * produce a confident output.
 *
 * Routing
 * -------
 *   tagged_briefs < min_nn_samples              → Layer 1 (rules)
 *   tagged_briefs ≥ min_nn_samples              → Layer 2 (nearest-neighbor)
 *      └─ if Layer 2 sample_size < 2, fall back → Layer 1
 *   tagged_briefs ≥ min_ml_samples              → Layer 3 (LightGBM, Step 7)
 *
 * "tagged_briefs" = count of past completed Promotion rows whose Brief
 * is populated (discount_pct NOT NULL is the required Brief field that
 * distinguishes Brief-tagged promos from legacy rows). Counted under
 * the current tenant scope.
 *
 * Layer 3 is deferred — Step 7 wires the LightGBM subprocess. When it
 * lands, this orchestrator just adds a third branch above Layer 2's
 * gate; the public contract here doesn't change.
 *
 * Output contract — same shape every layer returns:
 *   {value, lower, upper, basis, sample_size, layer}
 *
 * The `layer` field surfaces which path served the prediction so the
 * UI (Step 6) can show "rule-based — no comparable past campaigns yet"
 * vs "based on 4 similar past campaigns" attribution.
 */
class PredictionEngine
{
    public function __construct(
        private readonly RuleBasedLayer $rules,
        private readonly NearestNeighborLayer $nearestNeighbor,
        private readonly MlLayer $ml,
    ) {}

    /**
     * @param array{
     *   promotion_type?: ?string,
     *   discount_pct?: ?float,
     *   discount_type?: ?string,
     *   channel_mix?: ?array<string>,
     *   ad_spend_band?: ?string,
     *   audience?: ?string,
     *   lead_announcement_days?: ?int,
     * } $brief
     * @return array{
     *   value: float,
     *   lower: float,
     *   upper: float,
     *   basis: string,
     *   sample_size: int,
     *   layer: string,
     * }
     */
    public function predict(array $brief): array
    {
        $tenantId     = TenantContext::tenantId();
        $minNn        = (int) SystemSetting::get($tenantId, 'uplift.min_nn_samples', 5);
        $minMl        = (int) SystemSetting::get($tenantId, 'uplift.min_ml_samples', 50);
        $taggedBriefs = $this->countTaggedBriefs();

        // Cold start: not enough Brief-tagged history → rules only.
        if ($taggedBriefs < $minNn) {
            return $this->rules->predict($brief);
        }

        // Layer 3 (LightGBM) — try first when threshold met. Abstains
        // (sample_size=0) when the Python subprocess is unavailable, the
        // training set is insufficient on the Python side, or
        // training/inference fails. We then fall through to Layer 2.
        if ($taggedBriefs >= $minMl) {
            $mlResult = $this->ml->predict($brief);
            if ($mlResult['sample_size'] >= $minMl) {
                return $mlResult;
            }
        }

        // Layer 2 has enough total data to be worth asking. It may still
        // return sample_size below the floor (e.g., the top-K neighbors
        // mostly had zero baselines) — in that case fall back to Layer 1
        // so the operator sees a number rather than zero.
        $nn = $this->nearestNeighbor->predict($brief);

        if ($nn['sample_size'] < 2) {
            return $this->rules->predict($brief);
        }

        return $nn;
    }

    /**
     * Count of completed Brief-tagged promotions in the current tenant
     * scope. The Brief-tagged criterion (`discount_pct IS NOT NULL`)
     * separates redesign-era promotions from legacy rows that pre-date
     * the Campaign Brief migration.
     */
    private function countTaggedBriefs(): int
    {
        return Promotion::query()
            ->whereNotNull('discount_pct')
            ->whereDate('end_date', '<', Carbon::today())
            ->count();
    }
}
