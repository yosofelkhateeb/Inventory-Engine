<?php

namespace App\Services\Synthetic;

use App\Models\Sku;
use App\Models\Supplier;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ShopifyOrderFactory;

/**
 * SEA dataset orchestrator — the top-level service that drives the
 * synthetic-dataset milestone for the 30-SKU SEA Shopify-shape demo.
 *
 * Workflow
 * --------
 *   1. Read sea_sku_catalog.php (5 suppliers + 30 SKUs + per-SKU pathology / base_level)
 *   2. Create the 5 Supplier rows
 *   3. Create the 30 Sku rows with opening stock = round-to-nearest-10(1.2 × base_level × avg_lead_time_days)
 *   4. For each SKU, drive ShopifyOrderFactory at its assigned pathology:
 *        orders = factory.ordersFor(...)
 *        rows   = factory.toSalesHistory(orders)
 *        Apply seasonal × DoW multipliers per row, drop zero-quantity rows, batch-insert.
 *   5. Invoke SeaPromotionCampaignGenerator to seed ~57 Brief-tagged promos + boost SalesHistory in their windows.
 *
 * Why current_stock instead of stock_adjustments
 * ----------------------------------------------
 * The design doc proposed seeding opening stock as a StockAdjustment row,
 * but the stock_adjustments.reason_code enum doesn't have an 'opening'
 * value — and the engine reads current_stock directly off the Sku model
 * anyway. Setting current_stock at SKU creation is the simpler, correct
 * move; if an audit trail for the bootstrap becomes a requirement later,
 * a migration can add the enum value and we can replay it from the
 * catalog.
 *
 * Window
 * ------
 * End date is yesterday (matching ShopifyFixtureGenerator's "today =
 * not yet reported" convention). Default window is 913 days (~30 months).
 * Override via constructor for fast unit tests.
 *
 * Determinism
 * -----------
 * Same seed → same suppliers, SKUs, opening stocks, sales rows, promos.
 * The ShopifyOrderFactory and SeaPromotionCampaignGenerator are each
 * seeded independently from the constructor seed.
 */
class SeaDatasetSeeder
{
    private readonly array $catalog;
    private readonly SeaSeasonalCalendar $calendar;
    /** @var array<int, float> Mon=0 … Sun=6 */
    private readonly array $dowMultipliers;

    public function __construct(
        private readonly int $seed = 42,
        private readonly int $tenantId = 1,
        private readonly int $windowDays = 913,
        ?array $catalog = null,
        ?SeaSeasonalCalendar $calendar = null,
    ) {
        $this->catalog        = $catalog ?? require base_path('database/seeders/data/sea_sku_catalog.php');
        $this->calendar       = $calendar ?? new SeaSeasonalCalendar;
        $this->dowMultipliers = config('synthetic_dataset.day_of_week_multipliers', [1.0, 1.0, 1.05, 1.05, 1.1, 1.25, 1.15]);
    }

    /**
     * @return array{
     *   suppliers_created: int,
     *   skus_created: int,
     *   sales_rows_written: int,
     *   promos_created: int,
     *   sales_rows_boosted: int,
     * }
     */
    public function seed(): array
    {
        return TenantContext::run($this->tenantId, fn () => $this->doSeed());
    }

    private function doSeed(): array
    {
        $supplierMap = $this->seedSuppliers();
        $skus        = $this->seedSkus($supplierMap);
        $rowsWritten = $this->seedSalesHistory($skus);

        $promoResult = (new SeaPromotionCampaignGenerator(
            seed:     $this->seed,
            tenantId: $this->tenantId,
        ))->generate();

        return [
            'suppliers_created'  => count($supplierMap),
            'skus_created'       => count($skus),
            'sales_rows_written' => $rowsWritten,
            'promos_created'     => $promoResult['promos_created'],
            'sales_rows_boosted' => $promoResult['sales_rows_boosted'],
        ];
    }

    // ─── Suppliers ────────────────────────────────────────────────────────

    /** @return array<string, Supplier> Keyed by catalog supplier_key. */
    private function seedSuppliers(): array
    {
        $map = [];
        foreach ($this->catalog['suppliers'] as $key => $spec) {
            $map[$key] = Supplier::create([
                'tenant_id'          => $this->tenantId,
                'name'               => $spec['name'],
                'avg_lead_time_days' => $spec['avg_lead_time_days'],
                'lead_time_stddev'   => $spec['lead_time_stddev'],
            ]);
        }
        return $map;
    }

    // ─── SKUs + opening stock ────────────────────────────────────────────

    /**
     * @param  array<string, Supplier> $supplierMap
     * @return array<string, array{sku: Sku, pathology: string, base_level: int}>  Keyed by sku_code.
     *
     * Wrapped in Sku::withoutEvents to suppress SkuObserver::created(),
     * which dispatches RunForecastJob(new_sku) on every insert. On
     * QUEUE_CONNECTION=sync that subprocess runs synchronously BEFORE the
     * SalesHistory inserts that follow in seedSalesHistory(), leaving every
     * forecast with demand_rate=0 and pinning WMAPE at 100%. Forecasts are
     * produced separately via `php artisan forecast:sweep` after seeding.
     */
    private function seedSkus(array $supplierMap): array
    {
        return Sku::withoutEvents(function () use ($supplierMap) {
            $skus = [];
            foreach ($this->catalog['skus'] as $spec) {
                $supplier = $supplierMap[$spec['supplier_key']];
                $opening  = $this->roundToNearestTen(1.2 * $spec['base_level'] * $supplier->avg_lead_time_days);

                $sku = Sku::create([
                    'tenant_id'      => $this->tenantId,
                    'supplier_id'    => $supplier->id,
                    'sku_code'       => $spec['sku_code'],
                    'name'           => $spec['name'],
                    'category'       => $spec['category'],
                    'moq'            => $spec['moq'],
                    'unit_cost'      => $spec['unit_cost'],
                    'reorder_qty'    => $spec['reorder_qty'],
                    'current_stock'  => $opening,
                    'in_transit_qty' => 0,
                    'reserved_qty'   => 0,
                    'lead_time_days' => $supplier->avg_lead_time_days,
                ]);

                $skus[$spec['sku_code']] = [
                    'sku'        => $sku,
                    'pathology'  => $spec['pathology'],
                    'base_level' => $spec['base_level'],
                ];
            }
            return $skus;
        });
    }

    private function roundToNearestTen(float $value): int
    {
        return (int) (round($value / 10) * 10);
    }

    // ─── SalesHistory ────────────────────────────────────────────────────

    /** @param  array<string, array{sku: Sku, pathology: string, base_level: int}> $skus */
    private function seedSalesHistory(array $skus): int
    {
        $factory = new ShopifyOrderFactory(seed: $this->seed);
        $end     = Carbon::today()->subDay();
        $start   = $end->copy()->subDays($this->windowDays - 1);
        $now     = now()->toDateTimeString();
        $totalRows = 0;

        foreach ($skus as $entry) {
            /** @var Sku $sku */
            $sku       = $entry['sku'];
            $pathology = $entry['pathology'];
            $baseLevel = $entry['base_level'];

            $orders = $factory->ordersFor($pathology, $sku->sku_code, $start, $end, $baseLevel);
            $rows   = $factory->toSalesHistory($orders);

            $inserts = [];
            foreach ($rows as $r) {
                $date     = Carbon::parse($r['sale_date']);
                $seasonal = $this->calendar->multiplierFor($date);
                $dow      = $this->dowMultipliers[$date->dayOfWeekIso - 1] ?? 1.0;
                $qty      = max(0, (int) round($r['quantity_sold'] * $seasonal * $dow));

                if ($qty === 0) {
                    continue;
                }

                $inserts[] = [
                    'tenant_id'     => $this->tenantId,
                    'sku_id'        => $sku->id,
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
            $totalRows += count($inserts);
        }

        return $totalRows;
    }
}
