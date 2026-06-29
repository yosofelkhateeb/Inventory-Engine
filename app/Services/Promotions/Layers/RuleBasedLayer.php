<?php

namespace App\Services\Promotions\Layers;

use App\Models\SystemSetting;
use App\Support\TenantContext;

/**
 * Layer 1 of the Layered Hybrid Uplift Prediction Engine — always on.
 *
 * Produces a baseline uplift estimate from a Campaign Brief using a small
 * rule table whose coefficients live in `system_settings` under
 * `uplift.rule.*` (seeded by ForecastSettingsSeeder). Replaces the
 * gut-typed `expected_uplift_pct` input with a transparent, interpretable
 * formula:
 *
 *   value = base_by_type
 *         + discount_pct_coef * discount_pct
 *         + channel_count_coef * |channel_mix|
 *         + spend_band_bonus[band]
 *         + audience_bonus[audience]
 *
 * Clamped to ≥ 0, rounded to 1 decimal. Confidence band is a fixed
 * percentage of the point estimate (`uplift.rule.confidence_band_pct`)
 * because Layer 1 has no data to tighten the band against — that's the
 * job of Layers 2 and 3 once enough history exists.
 *
 * Replaces the v1 UpliftSuggester's settings-default fallback path.
 * The original `uplift_default.<type>` keys stay in place and are
 * read here as the `base_by_type` term — no re-seeding needed.
 *
 * Output shape matches the contract every layer returns:
 *   {value, lower, upper, basis, sample_size, layer}
 *
 * sample_size is always 0 for Layer 1 (rules don't depend on history).
 */
class RuleBasedLayer
{
    public const LAYER_NAME = 'rules';

    /**
     * @param array{
     *   promotion_type?: ?string,
     *   discount_pct?: ?float,
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
        $tenantId = TenantContext::tenantId();

        $base       = $this->baseByType($tenantId, $brief['promotion_type'] ?? null);
        $discount   = $this->discountAdjustment($tenantId, $brief['discount_pct'] ?? null);
        $channels   = $this->channelAdjustment($tenantId, $brief['channel_mix'] ?? []);
        $spend      = $this->spendBandAdjustment($tenantId, $brief['ad_spend_band'] ?? null);
        $audience   = $this->audienceAdjustment($tenantId, $brief['audience'] ?? null);

        $raw   = $base + $discount + $channels + $spend + $audience;
        $value = round(max(0.0, $raw), 1);

        $bandPct   = (float) SystemSetting::get($tenantId, 'uplift.rule.confidence_band_pct', 50);
        $bandWidth = $value * ($bandPct / 100);

        return [
            'value'       => $value,
            'lower'       => round(max(0.0, $value - $bandWidth), 1),
            'upper'       => round($value + $bandWidth, 1),
            'basis'       => $this->basis($brief),
            'sample_size' => 0,
            'layer'       => self::LAYER_NAME,
        ];
    }

    private function baseByType(int $tenantId, ?string $type): float
    {
        $key = ($type !== null && $type !== '')
            ? "uplift_default.{$type}"
            : 'uplift_default.generic';

        return (float) SystemSetting::get($tenantId, $key, 15);
    }

    private function discountAdjustment(int $tenantId, ?float $discount): float
    {
        if ($discount === null) {
            return 0.0;
        }
        $coef = (float) SystemSetting::get($tenantId, 'uplift.rule.discount_pct_coef', 0.5);
        return $coef * (float) $discount;
    }

    private function channelAdjustment(int $tenantId, array $channels): float
    {
        if (empty($channels)) {
            return 0.0;
        }
        $coef = (float) SystemSetting::get($tenantId, 'uplift.rule.channel_count_coef', 3.0);
        return $coef * count($channels);
    }

    private function spendBandAdjustment(int $tenantId, ?string $band): float
    {
        if ($band === null || $band === '') {
            return 0.0;
        }
        return (float) SystemSetting::get($tenantId, "uplift.rule.spend_band_bonus.{$band}", 0);
    }

    private function audienceAdjustment(int $tenantId, ?string $audience): float
    {
        if ($audience === null || $audience === '') {
            return 0.0;
        }
        return (float) SystemSetting::get($tenantId, "uplift.rule.audience_bonus.{$audience}", 0);
    }

    /**
     * Human-readable explanation of which rule terms fired. Surfaced to the
     * operator as the prediction's "basis" string in the engine prediction
     * panel.
     */
    private function basis(array $brief): string
    {
        $parts = ['rule-based baseline'];

        $type = $brief['promotion_type'] ?? null;
        if ($type !== null && $type !== '') {
            $parts[0] = "rule-based baseline for {$type}";
        }

        $bits = [];
        if (! empty($brief['discount_pct'])) {
            $bits[] = "{$brief['discount_pct']}% discount";
        }
        $channelCount = is_array($brief['channel_mix'] ?? null) ? count($brief['channel_mix']) : 0;
        if ($channelCount > 0) {
            $bits[] = "{$channelCount} channel(s)";
        }
        if (! empty($brief['ad_spend_band'])) {
            $bits[] = "{$brief['ad_spend_band']} spend";
        }
        if (! empty($brief['audience'])) {
            $bits[] = str_replace('_', ' ', $brief['audience']);
        }

        if (! empty($bits)) {
            $parts[] = 'with ' . implode(', ', $bits);
        }

        return implode(' ', $parts);
    }
}
