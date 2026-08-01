<?php

namespace App\Services\Synthetic;

use App\Models\Sku;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ShopifyOrderFactory;

/**
 * Keeps the hosted demo's sales feed current.
 *
 * SeaDatasetSeeder lays down a window ending yesterday *at seed time*, so a
 * dataset seeded a month ago is a month stale. Staleness is not cosmetic: the
 * Reports page cannot compute Portfolio WMAPE without recent actuals, and the
 * stale-feed banner fires on every SKU — the system correctly reporting that
 * its inputs have gone quiet.
 *
 * This appends the days that have elapsed since the feed's frontier — the
 * newest sale date in the dataset — using the same order factory and the same
 * demand shaping as the original seed (see SeaDemandMultipliers). It writes
 * *input* data only. No forecast, recommendation, or metric is ever fabricated.
 *
 * Demo-only. Guard invocation on `synthetic_dataset.feed_topup.enabled`.
 *
 * Pathology handling
 * ------------------
 * `stopped_selling` SKUs are skipped deliberately. ShopifyOrderFactory's
 * stopped-selling series is *window-relative* — it sells for ~60% of whatever
 * window it is handed, then dies. Topping such a SKU up over a short recent
 * window would resurrect it, destroying both the dead-stock signal on the
 * dashboard and the long-gap case the forecasting pipeline is meant to handle.
 * A SKU that stopped selling should stay stopped.
 */
class SeaDemoFeedTopUp
{
    /** Pathologies whose SKUs must not be topped up. */
    private const FROZEN_PATHOLOGIES = ['stopped_selling'];

    /**
     * Pathologies generated as a different shape during top-up.
     *
     * `promo_spike` must not be generated as itself here. ShopifyOrderFactory's
     * promo windows are three fixed 5-day blocks placed at 25/50/75% of
     * whatever window it is given — a fixed *count*, not a density. Over the
     * 913-day seed that is 15 of 913 days (1.6%). Over a 32-day top-up it is
     * still 15 days (47%), and over a nightly 1-day top-up all three anchors
     * collapse onto the single day (100%).
     *
     * The result is a recent tail where a large share of days carry a 3x
     * spike — a structural break that seasonal models cannot fit, pushing
     * candidate selection onto the baseline. Measured: it moved SARIMAX from
     * 12 wins to 2 and doubled ets_fallback.
     *
     * Generating base demand instead is also the more faithful answer. The
     * factory's promo days are an artefact of the seeded window, independent
     * of the `promotions` table; uplift for real campaigns is applied
     * separately by SeaPromotionCampaignGenerator. With no campaign scheduled
     * in the topped-up period, flat base demand is what should occur.
     */
    private const TOPUP_SHAPE_OVERRIDES = [
        'promo_spike'   => 'clean',
        'stockout_gaps' => 'clean',
        'new_sku'       => 'clean',
    ];

    /**
     * Pathologies whose generators are per-day and therefore safe to run over
     * any window length.
     *
     * Every pathology in the catalogue must appear in exactly one of
     * WINDOW_SAFE, FROZEN_PATHOLOGIES, or TOPUP_SHAPE_OVERRIDES. An unhandled
     * one throws rather than generating quietly wrong data — see
     * assertPathologyHandled(). The promo_spike bug was silent, and a nightly
     * job producing subtly wrong data is worse than one that stops.
     *
     * `stockout_gaps` and `new_sku` are overridden for the same reason as
     * promo_spike: both place features at fractions of the window they are
     * given. `stockout_gaps` zeroes two 10-14 day blocks at 30% and 70%, which
     * over a short top-up window is most of it; `new_sku` zeroes everything
     * before the last 30 days, which over a short window is nothing.
     */
    private const WINDOW_SAFE = ['clean', 'sparse', 'returns_heavy'];

    private readonly array $catalog;
    private readonly SeaDemandMultipliers $multipliers;

    public function __construct(
        private readonly int $seed = 42,
        private readonly int $tenantId = 1,
        ?array $catalog = null,
        ?SeaDemandMultipliers $multipliers = null,
    ) {
        $this->catalog     = $catalog ?? require base_path('database/seeders/data/sea_sku_catalog.php');
        $this->multipliers = $multipliers ?? new SeaDemandMultipliers;
    }

    /**
     * @param  CarbonInterface|null  $through  Last date to generate. Defaults to
     *                                        yesterday, matching the seeder's
     *                                        "today has not been reported yet"
     *                                        convention.
     * @return array{
     *   through: string,
     *   skus_topped_up: int,
     *   skus_already_current: int,
     *   skus_frozen: int,
     *   skus_missing: int,
     *   rows_written: int,
     * }
     */
    public function run(?CarbonInterface $through = null): array
    {
        return TenantContext::run($this->tenantId, fn () => $this->doRun($through));
    }

    private function doRun(?CarbonInterface $through): array
    {
        $through = $through
            ? Carbon::parse($through->toDateString())
            : Carbon::today()->subDay();

        $factory = new ShopifyOrderFactory(seed: $this->seed);
        $now     = now()->toDateTimeString();

        $toppedUp = 0;
        $current  = 0;
        $frozen   = 0;
        $missing  = 0;
        $rows     = 0;

        // Anchor on the feed's frontier — the newest sale date across the whole
        // dataset — not on each SKU's own last sale.
        //
        // A `sparse` SKU can legitimately go weeks without selling; that gap is
        // the intermittency the pathology exists to produce. Anchoring per-SKU
        // would read those gaps as "days missing" and backfill them, quietly
        // eroding the intermittent demand that puts those SKUs in Croston
        // territory. The frontier answers the actual question: how far behind
        // real time has the feed as a whole fallen?
        $frontier = DB::table('sales_history')
            ->where('tenant_id', $this->tenantId)
            ->max('sale_date');

        if ($frontier === null) {
            // Nothing seeded. Topping up an empty dataset would invent a window
            // from nowhere and mask a broken seed.
            return [
                'through'              => $through->toDateString(),
                'skus_topped_up'       => 0,
                'skus_already_current' => 0,
                'skus_frozen'          => 0,
                'skus_missing'         => count($this->catalog['skus']),
                'rows_written'         => 0,
            ];
        }

        $start = Carbon::parse($frontier)->addDay()->startOfDay();

        foreach ($this->catalog['skus'] as $spec) {
            if (in_array($spec['pathology'], self::FROZEN_PATHOLOGIES, true)) {
                $frozen++;
                continue;
            }

            if ($start->greaterThan($through)) {
                $current++;
                continue;
            }

            $sku = Sku::where('sku_code', $spec['sku_code'])->first();
            if (! $sku) {
                $missing++;
                continue;
            }

            $this->assertPathologyHandled($spec['pathology'], $spec['sku_code']);

            $shape = self::TOPUP_SHAPE_OVERRIDES[$spec['pathology']] ?? $spec['pathology'];

            $written = $this->generateRange(
                $factory, $sku->id, $shape, $spec['sku_code'],
                (int) $spec['base_level'], $start, $through, $now,
            );

            $rows += $written;
            $toppedUp++;
        }

        return [
            'through'              => $through->toDateString(),
            'skus_topped_up'       => $toppedUp,
            'skus_already_current' => $current,
            'skus_frozen'          => $frozen,
            'skus_missing'         => $missing,
            'rows_written'         => $rows,
        ];
    }

    /**
     * Refuse to top up a pathology nobody has reasoned about.
     *
     * Several ShopifyOrderFactory generators place their features at fractions
     * of the window they are handed, so they produce something quite different
     * over a 30-day top-up than over the 913-day seed. Adding a new pathology
     * to the catalogue must be a deliberate decision here, not a silent
     * behaviour change in a nightly job.
     */
    private function assertPathologyHandled(string $pathology, string $skuCode): void
    {
        if (in_array($pathology, self::WINDOW_SAFE, true)
            || array_key_exists($pathology, self::TOPUP_SHAPE_OVERRIDES)) {
            return;
        }

        throw new \RuntimeException(
            "SeaDemoFeedTopUp has no rule for pathology '{$pathology}' (SKU {$skuCode}). "
            .'Classify it in WINDOW_SAFE, FROZEN_PATHOLOGIES, or TOPUP_SHAPE_OVERRIDES: '
            .'generators that place features at fractions of the window produce '
            .'wrong data over short top-up ranges.'
        );
    }

    private function generateRange(
        ShopifyOrderFactory $factory,
        int $skuId,
        string $pathology,
        string $skuCode,
        int $baseLevel,
        Carbon $start,
        Carbon $through,
        string $now,
    ): int {
        $orders = $factory->ordersFor($pathology, $skuCode, $start, $through, $baseLevel);

        $inserts = [];
        foreach ($factory->toSalesHistory($orders) as $r) {
            $date = Carbon::parse($r['sale_date']);
            $qty  = $this->multipliers->apply((float) $r['quantity_sold'], $date);

            if ($qty === 0) {
                continue;
            }

            $inserts[] = [
                'tenant_id'     => $this->tenantId,
                'sku_id'        => $skuId,
                'sale_date'     => $r['sale_date'],
                'quantity_sold' => $qty,
                'is_promotion'  => $r['is_promotion'] ? 1 : 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('sales_history')->insert($chunk);
        }

        return count($inserts);
    }
}
