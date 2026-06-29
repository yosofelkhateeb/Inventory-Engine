<?php

namespace App\Services\Synthetic;

use App\Models\Promotion;
use App\Models\Sku;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * SEA-themed Brief-tagged promotion campaign generator.
 *
 * Runs AFTER the SEA dataset orchestrator (Step 4) has written 30 SKUs +
 * SalesHistory. Seeds ~65 Brief-tagged Promotion rows anchored to the SEA
 * e-commerce calendar (CNY, Hari Raya Puasa/Haji, mega sale days 9.9 /
 * 10.10 / 11.11 / 12.12, Black Friday / Cyber Monday, Christmas) plus
 * quarterly clearance and monthly bundle/loyalty rotations. Boosts
 * SalesHistory quantity_sold in promo windows so the engine's
 * actualUplift() helper computes non-zero lifts across the full
 * 30-month SEA window.
 *
 * Gap rule
 * --------
 * The Uplift Step 8 retrospective established that overlapping 30-day
 * baseline windows between promos with shared SKUs contaminate the
 * engine's actualUplift() reading. This generator enforces the rule by
 * design rather than by runtime check: the anchored schedule restricts
 * affects_all_skus to 11.11, CNY, and Hari Raya Puasa only (gaps ≥60
 * days), and the busy Nov-Dec mega-sale cluster rotates category
 * targeting (equipment → bundle → accessory → bundle) so no two
 * overlapping-SKU campaigns fall inside each other's 30-day baseline.
 *
 * Output target: 27 anchored + ~10 quarterly clearance + ~20 bundle/other
 * rotations = ~57 promos. Well above the 50-promo Layer 3 LightGBM
 * activation threshold; under the 70-promo soft ceiling.
 */
class SeaPromotionCampaignGenerator
{
    /**
     * Brief templates per promotion_type. Each template carries the
     * plausible bounds for an SEA e-commerce promo of that shape and
     * shapes the promotion distribution the LightGBM layer trains on.
     */
    private const TEMPLATES = [
        'flash' => [
            'discount_range' => [25, 60],
            'discount_type'  => 'percent_off',
            'channels_pool'  => ['paid_social', 'email', 'influencer', 'sms', 'display_ads'],
            'spend_bands'    => ['mid', 'high', 'very_high'],
            'audiences'      => ['both', 'new_acquisition'],
            'lead_days'      => [3, 14],
            'lift_range'     => [40, 80],
        ],
        'clearance' => [
            'discount_range' => [40, 70],
            'discount_type'  => 'percent_off',
            'channels_pool'  => ['email', 'organic_social', 'in_store'],
            'spend_bands'    => ['none', 'low', 'mid'],
            'audiences'      => ['existing_customers'],
            'lead_days'      => [0, 7],
            'lift_range'     => [25, 60],
        ],
        'seasonal' => [
            'discount_range' => [15, 35],
            'discount_type'  => 'percent_off',
            'channels_pool'  => ['paid_social', 'email', 'organic_social', 'display_ads'],
            'spend_bands'    => ['mid', 'high'],
            'audiences'      => ['both'],
            'lead_days'      => [7, 21],
            'lift_range'     => [20, 45],
        ],
        'bundle' => [
            'discount_range' => [10, 25],
            'discount_type'  => 'bundle_pricing',
            'channels_pool'  => ['email', 'paid_social', 'organic_social'],
            'spend_bands'    => ['low', 'mid'],
            'audiences'      => ['existing_customers', 'both'],
            'lead_days'      => [3, 14],
            'lift_range'     => [10, 30],
        ],
        'other' => [
            'discount_range' => [5, 20],
            'discount_type'  => 'fixed_amount_off',
            'channels_pool'  => ['email', 'sms'],
            'spend_bands'    => ['none', 'low'],
            'audiences'      => ['existing_customers'],
            'lead_days'      => [0, 7],
            'lift_range'     => [8, 25],
        ],
    ];

    /**
     * Anchored SEA calendar campaigns. Format:
     *   [event_date, promotion_type, targeting_kind, duration_days, categories]
     *
     * targeting_kind ∈ {'all', 'category', 'specific'}
     * categories array used only when targeting_kind = 'category'
     *
     * Cluster rotation in Nov-Dec each year: 11.11=all, BF=[equipment],
     * CM=[bundle], 12.12=[accessory], Xmas=[bundle]. Categories
     * intentionally non-overlapping within 31 days.
     */
    private const ANCHORED_EVENTS = [
        // 2023 partial year (window starts 2023-11-12)
        ['2023-11-24', 'flash',    'category', 4, ['equipment']],     // Black Friday
        ['2023-11-27', 'flash',    'category', 2, ['bundle']],      // Cyber Monday
        ['2023-12-12', 'flash',    'category', 3, ['accessory']],   // 12.12
        ['2023-12-25', 'seasonal', 'category', 7, ['bundle']],      // Christmas
        // 2024
        ['2024-02-10', 'seasonal', 'all',      7, []],              // CNY 2024
        ['2024-04-10', 'seasonal', 'all',      5, []],              // Hari Raya Puasa 2024
        ['2024-06-17', 'seasonal', 'category', 3, ['bundle']],      // Hari Raya Haji 2024
        ['2024-09-09', 'flash',    'category', 3, ['accessory']],   // 9.9
        ['2024-10-10', 'flash',    'category', 3, ['bundle']],      // 10.10
        ['2024-11-11', 'flash',    'all',      3, []],              // 11.11 anchor
        ['2024-11-29', 'flash',    'category', 4, ['equipment']],     // Black Friday
        ['2024-12-02', 'flash',    'category', 2, ['bundle']],      // Cyber Monday
        ['2024-12-12', 'flash',    'category', 3, ['accessory']],   // 12.12
        ['2024-12-25', 'seasonal', 'category', 7, ['bundle']],      // Christmas
        // 2025
        ['2025-01-29', 'seasonal', 'all',      7, []],              // CNY 2025
        ['2025-03-31', 'seasonal', 'all',      5, []],              // Hari Raya Puasa 2025
        ['2025-06-07', 'seasonal', 'category', 3, ['bundle']],      // Hari Raya Haji 2025
        ['2025-09-09', 'flash',    'category', 3, ['accessory']],   // 9.9
        ['2025-10-10', 'flash',    'category', 3, ['bundle']],      // 10.10
        ['2025-11-11', 'flash',    'all',      3, []],              // 11.11
        ['2025-11-28', 'flash',    'category', 4, ['equipment']],     // Black Friday
        ['2025-12-01', 'flash',    'category', 2, ['bundle']],      // Cyber Monday
        ['2025-12-12', 'flash',    'category', 3, ['accessory']],   // 12.12
        ['2025-12-25', 'seasonal', 'category', 7, ['bundle']],      // Christmas
        // 2026 partial year (window ends 2026-05-12)
        ['2026-02-17', 'seasonal', 'all',      7, []],              // CNY 2026
        // HRP 2026 falls only 26 days after CNY 2026's window — below the
        // 31-day gap rule for affects_all_skus campaigns. Downgraded to
        // category targeting to preserve the rule at the window edge.
        ['2026-03-20', 'seasonal', 'category', 5, ['accessory']],   // Hari Raya Puasa 2026
    ];

    /** Window endpoints — match config('synthetic_dataset.window_days'). */
    private const WINDOW_START = '2023-11-12';
    private const WINDOW_END   = '2026-05-12';

    private Randomizer $rng;

    public function __construct(
        private readonly int $seed = 42,
        private readonly int $tenantId = 1,
    ) {
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    /**
     * Build all campaigns, write them to the database, and boost
     * SalesHistory in their windows.
     *
     * @return array{promos_created: int, sales_rows_boosted: int}
     */
    public function generate(): array
    {
        $skusByCategory = $this->loadSkusByCategory();
        $allSkuIds      = collect($skusByCategory)->flatten()->all();

        if (empty($allSkuIds)) {
            return ['promos_created' => 0, 'sales_rows_boosted' => 0];
        }

        $created      = 0;
        $rowsBoosted  = 0;

        foreach ($this->buildSchedule() as $campaign) {
            $affectedIds = $this->resolveAffectedSkuIds($campaign, $skusByCategory, $allSkuIds);
            if (empty($affectedIds)) {
                continue;
            }

            $brief        = $this->generateBrief($campaign['type']);
            $realizedLift = $this->intInRange(self::TEMPLATES[$campaign['type']]['lift_range']);

            $promo = Promotion::create([
                'tenant_id'              => $this->tenantId,
                'name'                   => $this->generateName($campaign['type'], $campaign['start']),
                'promotion_type'         => $campaign['type'],
                'start_date'             => $campaign['start'],
                'end_date'               => $campaign['end'],
                // Historical promo: set expected_uplift_pct = realized so any
                // re-forecast against the past date has the value SARIMAX expects.
                'expected_uplift_pct'    => $realizedLift,
                'discount_pct'           => $brief['discount_pct'],
                'discount_type'          => $brief['discount_type'],
                'channel_mix'            => $brief['channel_mix'],
                'ad_spend_band'          => $brief['ad_spend_band'],
                'audience'               => $brief['audience'],
                'lead_announcement_days' => $brief['lead_announcement_days'],
                'affects_all_skus'       => $campaign['targeting'] === 'all',
                'applies_to_categories'  => $campaign['categories'] ?: null,
            ]);

            if ($campaign['targeting'] === 'specific') {
                $promo->skus()->sync($affectedIds);
            }

            $multiplier = 1 + ($realizedLift / 100);
            // whereDate avoids a SQLite quirk where the date column stores
            // a datetime suffix and `whereBetween('sale_date', [start, end])`
            // excludes the upper bound via text comparison
            // ('2024-11-12 00:00:00' > '2024-11-12').
            $boosted    = DB::table('sales_history')
                ->where('tenant_id', $this->tenantId)
                ->whereIn('sku_id', $affectedIds)
                ->whereDate('sale_date', '>=', $campaign['start']->toDateString())
                ->whereDate('sale_date', '<=', $campaign['end']->toDateString())
                ->update([
                    'quantity_sold' => DB::raw("ROUND(quantity_sold * {$multiplier})"),
                    'is_promotion'  => 1,
                ]);

            $created++;
            $rowsBoosted += (int) $boosted;
        }

        return [
            'promos_created'     => $created,
            'sales_rows_boosted' => $rowsBoosted,
        ];
    }

    // ─── Schedule construction ────────────────────────────────────────────

    /** @return list<array{type:string,targeting:string,categories:list<string>,start:Carbon,end:Carbon}> */
    private function buildSchedule(): array
    {
        $windowStart = Carbon::parse(self::WINDOW_START);
        $windowEnd   = Carbon::parse(self::WINDOW_END);
        $campaigns   = [];

        // 1. Anchored SEA events.
        foreach (self::ANCHORED_EVENTS as [$dateStr, $type, $targeting, $duration, $categories]) {
            // Center the campaign window on the anchor date.
            $start = Carbon::parse($dateStr)->subDays(intdiv($duration, 2));
            $end   = $start->copy()->addDays($duration - 1);
            if ($end->lt($windowStart) || $start->gt($windowEnd)) {
                continue;
            }
            $campaigns[] = [
                'type'       => $type,
                'targeting'  => $targeting,
                'categories' => $categories,
                'start'      => $start,
                'end'        => $end,
            ];
        }

        // 2. Quarterly clearance campaigns — Jan/Apr/Jul/Oct 15th. Category
        // rotates each quarter so adjacent quarters don't overlap the same SKUs.
        $clearanceCategories = ['equipment', 'accessory', 'bundle'];
        $clearanceIdx        = 0;
        $cursor              = Carbon::parse('2024-01-15');
        while ($cursor->lte($windowEnd)) {
            if ($cursor->gte($windowStart)) {
                $duration = $this->rng->getInt(10, 21);
                $campaigns[] = [
                    'type'       => 'clearance',
                    'targeting'  => 'category',
                    'categories' => [$clearanceCategories[$clearanceIdx % 3]],
                    'start'      => $cursor->copy(),
                    'end'        => $cursor->copy()->addDays($duration - 1),
                ];
                $clearanceIdx++;
            }
            $cursor->addMonths(3);
        }

        // 3. Monthly bundle/other rotations — every 35 days from a fixed
        // start, alternating bundle and other types. Specific targeting
        // (1-3 SKUs) so they never overlap each other on SKU level.
        $cursor = Carbon::parse('2023-12-15');
        $idx    = 0;
        while ($cursor->lte($windowEnd)) {
            if ($cursor->gte($windowStart)) {
                $duration  = $this->rng->getInt(14, 30);
                $type      = $idx % 3 === 0 ? 'other' : 'bundle';
                $targeting = 'specific';
                $campaigns[] = [
                    'type'       => $type,
                    'targeting'  => $targeting,
                    'categories' => [],
                    'start'      => $cursor->copy(),
                    'end'        => $cursor->copy()->addDays($duration - 1),
                ];
                $idx++;
            }
            $cursor->addDays(35);
        }

        return $campaigns;
    }

    // ─── Targeting resolution ─────────────────────────────────────────────

    /**
     * @param  array<string, list<int>>  $skusByCategory
     * @param  list<int>                 $allSkuIds
     * @return list<int>
     */
    private function resolveAffectedSkuIds(array $campaign, array $skusByCategory, array $allSkuIds): array
    {
        if ($campaign['targeting'] === 'all') {
            return $allSkuIds;
        }
        if ($campaign['targeting'] === 'category') {
            $ids = [];
            foreach ($campaign['categories'] as $cat) {
                foreach ($skusByCategory[$cat] ?? [] as $id) {
                    $ids[] = $id;
                }
            }
            return array_values(array_unique($ids));
        }
        // specific — pick 1-3 SKUs deterministically from the full catalog
        $count = min($this->rng->getInt(1, 3), count($allSkuIds));
        $shuffled = $this->rng->shuffleArray($allSkuIds);
        return array_slice($shuffled, 0, $count);
    }

    /** @return array<string, list<int>> Keyed by category, values are SKU ids in tenant scope. */
    private function loadSkusByCategory(): array
    {
        $byCategory = [];
        foreach (Sku::all() as $sku) {
            $byCategory[$sku->category][] = $sku->id;
        }
        return $byCategory;
    }

    // ─── Brief generation ─────────────────────────────────────────────────

    /**
     * @return array{
     *   discount_pct: int,
     *   discount_type: string,
     *   channel_mix: list<string>,
     *   ad_spend_band: string,
     *   audience: string,
     *   lead_announcement_days: int,
     * }
     */
    private function generateBrief(string $type): array
    {
        $t = self::TEMPLATES[$type];

        $channelCount    = $this->rng->getInt(1, min(4, count($t['channels_pool'])));
        $shuffledPool    = $this->rng->shuffleArray($t['channels_pool']);
        $channelMix      = array_slice($shuffledPool, 0, $channelCount);

        return [
            'discount_pct'           => $this->intInRange($t['discount_range']),
            'discount_type'          => $t['discount_type'],
            'channel_mix'            => $channelMix,
            'ad_spend_band'          => $this->pick($t['spend_bands']),
            'audience'               => $this->pick($t['audiences']),
            'lead_announcement_days' => $this->intInRange($t['lead_days']),
        ];
    }

    private function generateName(string $type, Carbon $start): string
    {
        $month = $start->format('M Y');
        $pools = [
            'flash'     => ["{$month} Flash Sale", "{$month} Lightning Deal", "{$month} 48-Hour Special"],
            'clearance' => ["{$month} End-of-Season Clearance", "{$month} Inventory Reset", "{$month} Last Chance Sale"],
            'seasonal'  => ["{$month} Seasonal Push", "{$month} Holiday Campaign", "{$month} Featured Drop"],
            'bundle'    => ["{$month} Bundle Bonanza", "{$month} Pair & Save", "{$month} Combo Deal"],
            'other'     => ["{$month} Loyalty Reward", "{$month} VIP Special", "{$month} Members-Only"],
        ];
        return $this->pick($pools[$type] ?? ["{$month} {$type}"]);
    }

    // ─── RNG helpers ──────────────────────────────────────────────────────

    /** @param array{int, int} $range */
    private function intInRange(array $range): int
    {
        return $this->rng->getInt($range[0], $range[1]);
    }

    /**
     * @template T
     * @param  list<T> $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }
}
