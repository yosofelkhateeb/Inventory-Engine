<?php

namespace Tests\Fixtures;

use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\InventoryEngine\DecisionScorer;
use App\Services\Training\InventorySimulator;
use Carbon\Carbon;

/**
 * Orchestrator: builds an N-SKU Shopify-shape fixture with a realistic
 * pathology mix and writes it to the database.
 *
 * The pathology mix defaults to what a typical first-client Shopify sync
 * produces: mostly healthy SKUs with a handful of messy shapes sprinkled
 * in. Tuneable via $pathologyMix.
 */
final class ShopifyFixtureGenerator
{
    /**
     * Default mix for a 30-SKU first-client benchmark — shape of what a
     * real Shopify store looks like after 12 months of operation.
     * Values are weights; normalisation handled at draw time.
     *
     * @var array<string, int>
     */
    public const DEFAULT_MIX = [
        'clean'           => 12,
        'promo_spike'     => 6,
        'sparse'          => 4,
        'stockout_gaps'   => 3,
        'returns_heavy'   => 2,
        'stopped_selling' => 2,
        'new_sku'         => 1,
    ];

    public function __construct(
        private readonly int $seed = 42,
        private readonly int $tenantId = 1,
    ) {}

    /**
     * Generate and persist a fixture.
     *
     * When $simulate is true, the fixture also drives an InventorySimulator
     * pass per SKU, populating lead_time_observations, stockout_events,
     * and inventory_position_snapshots — the ground truth tables Chunk 3's
     * calibration job fits on. This is the entry point for generating the
     * 3-year training dataset (call with historyDays >= 1095).
     *
     * @param  int                   $skuCount
     * @param  int                   $historyDays     Total span of the series
     * @param  array<string, int>    $pathologyMix
     * @param  bool                  $simulate        Run InventorySimulator after sales generation
     * @return array<int, array{sku_id:int, sku_code:string, pathology:string, rows_written:int, simulation?:array}>
     */
    public function generate(
        int $skuCount = 30,
        int $historyDays = 365,
        array $pathologyMix = self::DEFAULT_MIX,
        bool $simulate = false,
    ): array {
        $supplier = Supplier::withoutGlobalScopes()->first()
            ?? Supplier::factory()->create(['tenant_id' => $this->tenantId]);

        $assignments = $this->assignPathologies($skuCount, $pathologyMix);
        $factory     = new ShopifyOrderFactory($this->seed);

        // End one day ago so the trailing-zero / staleness checks behave
        // as they would in production (today = "not yet reported").
        $to   = Carbon::today()->subDay();
        $from = (clone $to)->subDays($historyDays - 1);

        // Construct simulator once. Reused per SKU; keeps the RNG stream
        // continuous so different SKUs see different lead-time draws.
        $simulator = $simulate
            ? new InventorySimulator(new DecisionScorer(), tenantId: $this->tenantId, seed: $this->seed)
            : null;

        $summary   = [];
        $skuCounter = 0;

        foreach ($assignments as $pathology) {
            $skuCounter++;
            $skuCode = sprintf('BENCH-%04d', $skuCounter);

            $sku = Sku::withoutEvents(function () use ($skuCode, $supplier, $pathology) {
                return Sku::withoutGlobalScopes()->create([
                    'tenant_id'      => $this->tenantId,
                    'supplier_id'    => $supplier->id,
                    'sku_code'       => $skuCode,
                    'name'           => "Benchmark SKU {$skuCode} ({$pathology})",
                    'category'       => 'accessory',
                    'moq'            => 12,
                    'unit_cost'      => 2500,
                    'reorder_qty'    => 50,
                    'current_stock'  => 100,
                    'in_transit_qty' => 0,
                    'reserved_qty'   => 0,
                    'lead_time_days' => 7,
                ]);
            });

            $baseLevel = $pathology === 'sparse' ? 1 : $this->baseLevelFor($pathology);
            $orders    = $factory->ordersFor($pathology, $skuCode, $from, $to, $baseLevel);
            $rows      = $factory->toSalesHistory($orders);

            $inserts = [];
            foreach ($rows as $r) {
                $inserts[] = [
                    'tenant_id'     => $this->tenantId,
                    'sku_id'        => $sku->id,
                    'sale_date'     => $r['sale_date'],
                    'quantity_sold' => $r['quantity_sold'],
                    'is_promotion'  => $r['is_promotion'],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }

            if (! empty($inserts)) {
                foreach (array_chunk($inserts, 500) as $chunk) {
                    SalesHistory::insert($chunk);
                }
            }

            $entry = [
                'sku_id'       => $sku->id,
                'sku_code'     => $skuCode,
                'pathology'    => $pathology,
                'rows_written' => count($inserts),
            ];

            // Drive the inventory simulator if requested. Builds the
            // dailyDemand map by collapsing same-date rows (sales_history
            // already aggregates per day, but be defensive).
            if ($simulator !== null) {
                $dailyDemand = [];
                foreach ($rows as $r) {
                    $d = $r['sale_date'];
                    $dailyDemand[$d] = ($dailyDemand[$d] ?? 0) + (int) $r['quantity_sold'];
                }
                $entry['simulation'] = $simulator->simulate(
                    $sku,
                    $supplier,
                    $dailyDemand,
                    Carbon::parse($from->format('Y-m-d')),
                    Carbon::parse($to->format('Y-m-d')),
                );
            }

            $summary[] = $entry;
        }

        return $summary;
    }

    /**
     * Distribute pathologies across N SKUs using the weighted mix.
     * Deterministic for a given (seed, count, mix).
     *
     * @param  array<string, int> $mix
     * @return array<int, string>
     */
    private function assignPathologies(int $count, array $mix): array
    {
        $total      = array_sum($mix);
        $out        = [];
        $allocated  = 0;

        foreach ($mix as $pathology => $weight) {
            $share = (int) floor($count * $weight / $total);
            for ($i = 0; $i < $share; $i++) {
                $out[] = $pathology;
            }
            $allocated += $share;
        }

        // Distribute rounding remainder using the heaviest mix key.
        $dominant = array_keys($mix, max($mix))[0];
        while ($allocated < $count) {
            $out[] = $dominant;
            $allocated++;
        }

        // Shuffle deterministically so SKU 1 isn't always 'clean'.
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($this->seed + 1));
        return $rng->shuffleArray($out);
    }

    private function baseLevelFor(string $pathology): int
    {
        return match ($pathology) {
            'clean'           => 10,
            'promo_spike'     => 8,
            'stockout_gaps'   => 12,
            'returns_heavy'   => 10,
            'stopped_selling' => 15,
            'new_sku'         => 10,
            'sparse'          => 1,
        };
    }
}
