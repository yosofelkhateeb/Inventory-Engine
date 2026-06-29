<?php

namespace App\Services\Promotions;

use App\Models\Promotion;

/**
 * Single source of truth for Campaign Brief → 23-dim feature vector.
 *
 * Used by both Layer 2 (NearestNeighborLayer for cosine distance) and
 * Layer 3 (MlLayer for LightGBM regression). Same encoding so a Brief
 * has the same vector regardless of which layer consumes it.
 *
 * All components in [0, 1] so distance / similarity metrics aren't
 * dominated by raw magnitude differences across fields.
 *
 * Vector layout (dimension index → field):
 *
 *   [0]      discount_pct ÷ 100
 *   [1..5]   discount_type one-hot (5 enum values; order matches
 *            Promotion::DISCOUNT_TYPES)
 *   [6..12]  channel_mix multi-hot (7 channel tags; order matches
 *            Promotion::CHANNEL_TAGS)
 *   [13]     ad_spend_band ordinal (none=0, low=0.25, mid=0.5,
 *            high=0.75, very_high=1.0)
 *   [14..16] audience one-hot (3 enum values; order matches
 *            Promotion::AUDIENCES)
 *   [17]     lead_announcement_days ÷ 30 (capped at 1.0)
 *   [18..22] promotion_type one-hot (seasonal, flash, clearance,
 *            bundle, other)
 */
class BriefVectorizer
{
    public const DIMENSIONS = 23;

    private const SPEND_BAND_ORDINAL = [
        'none'      => 0.0,
        'low'       => 0.25,
        'mid'       => 0.5,
        'high'      => 0.75,
        'very_high' => 1.0,
    ];

    private const PROMOTION_TYPES = ['seasonal', 'flash', 'clearance', 'bundle', 'other'];

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
     * @return array<float>
     */
    public function vectorize(array $brief): array
    {
        $vec = [];

        // discount_pct
        $vec[] = min(1.0, max(0.0, ((float) ($brief['discount_pct'] ?? 0)) / 100.0));

        // discount_type one-hot
        $dt = $brief['discount_type'] ?? null;
        foreach (Promotion::DISCOUNT_TYPES as $t) {
            $vec[] = $dt === $t ? 1.0 : 0.0;
        }

        // channel_mix multi-hot
        $channels = is_array($brief['channel_mix'] ?? null) ? $brief['channel_mix'] : [];
        foreach (Promotion::CHANNEL_TAGS as $c) {
            $vec[] = in_array($c, $channels, true) ? 1.0 : 0.0;
        }

        // ad_spend_band ordinal
        $vec[] = self::SPEND_BAND_ORDINAL[$brief['ad_spend_band'] ?? ''] ?? 0.0;

        // audience one-hot
        $aud = $brief['audience'] ?? null;
        foreach (Promotion::AUDIENCES as $a) {
            $vec[] = $aud === $a ? 1.0 : 0.0;
        }

        // lead_announcement_days
        $vec[] = min(1.0, max(0.0, ((float) ($brief['lead_announcement_days'] ?? 0)) / 30.0));

        // promotion_type one-hot
        $pt = $brief['promotion_type'] ?? null;
        foreach (self::PROMOTION_TYPES as $t) {
            $vec[] = $pt === $t ? 1.0 : 0.0;
        }

        return $vec;
    }
}
