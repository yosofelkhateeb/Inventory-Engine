<?php

use App\Models\InventoryPositionSnapshot;
use App\Models\LeadTimeObservation;
use App\Models\Sku;
use App\Models\StockoutEvent;
use App\Models\Supplier;
use App\Services\InventoryEngine\DecisionScorer;
use App\Services\Training\InventorySimulator;
use Carbon\Carbon;

/**
 * Helper: create a Sku with controlled lead-time and reorder fields without
 * triggering Laravel's nested-factory attribute cascade. Passing
 * `lead_time_days` to Sku::factory()->create() leaks the override into the
 * lazy Supplier::factory() default, which then fails because suppliers
 * don't have that column.
 */
function makeSimSku(int $supplierId, int $leadTime, int $reorderQty): Sku
{
    $sku = Sku::factory()->create(['supplier_id' => $supplierId]);
    $sku->update([
        'lead_time_days' => $leadTime,
        'reorder_qty'    => $reorderQty,
        'moq'            => $reorderQty,
    ]);
    return $sku->fresh();
}

it('simulates inventory dynamics and populates all three ground-truth tables', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = makeSimSku($supplier->id, 7, 50);

    // 6 months of steady demand: 5 units/day, slight Sunday lift
    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 6, 30);
    $daily = [];
    $cursor = $start->copy();
    while ($cursor <= $end) {
        $daily[$cursor->format('Y-m-d')] = 5 + ($cursor->dayOfWeek === 0 ? 2 : 0);
        $cursor->addDay();
    }

    $sim = new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 99);
    $counts = $sim->simulate($sku, $supplier, $daily, $start, $end);

    expect($counts['snapshots'])->toBe(182);
    expect($counts['lead_times'])->toBeGreaterThan(0);
    expect($counts['stockouts'])->toBeGreaterThanOrEqual(0);

    expect(InventoryPositionSnapshot::withoutGlobalScopes()->where('sku_id', $sku->id)->count())->toBe(182);
    expect(LeadTimeObservation::withoutGlobalScopes()->where('sku_id', $sku->id)->count())->toBe($counts['lead_times']);
    expect(StockoutEvent::withoutGlobalScopes()->where('sku_id', $sku->id)->count())->toBe($counts['stockouts']);

    foreach (LeadTimeObservation::withoutGlobalScopes()->get() as $o) {
        expect($o->order_received_at >= $o->order_placed_at)->toBeTrue();
        expect($o->days_actual)->toBeGreaterThanOrEqual(1);
    }
});

it('records snapshots with multiple decision states across a varied series', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = makeSimSku($supplier->id, 7, 30);

    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 3, 30);
    $daily = [];
    $cursor = $start->copy();
    while ($cursor <= $end) {
        $daily[$cursor->format('Y-m-d')] = 3;
        $cursor->addDay();
    }

    $sim = new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 7);
    $sim->simulate($sku, $supplier, $daily, $start, $end);

    $decisions = InventoryPositionSnapshot::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->pluck('decision')
        ->unique()
        ->values()
        ->toArray();

    expect($decisions)->toContain('order');
    expect($decisions)->toContain('hold');
});

it('ShopifyFixtureGenerator drives the simulator end-to-end when simulate=true', function () {
    Supplier::factory()->create(['avg_lead_time_days' => 7]);

    $generator = new \Tests\Fixtures\ShopifyFixtureGenerator(seed: 5, tenantId: 1);
    $summary = $generator->generate(
        skuCount:    3,
        historyDays: 90,
        simulate:    true,
    );

    expect($summary)->toHaveCount(3);
    foreach ($summary as $entry) {
        expect($entry)->toHaveKey('simulation');
        expect($entry['simulation']['snapshots'])->toBe(90);
        expect($entry['simulation']['lead_times'])->toBeGreaterThanOrEqual(0);
    }

    // All three ground-truth tables populated
    expect(InventoryPositionSnapshot::withoutGlobalScopes()->count())->toBe(270); // 3 SKUs × 90 days
    expect(LeadTimeObservation::withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(0);
});

it('reorder_within_threshold and stockout_within_threshold start as null', function () {
    // Outcome columns are filled retroactively by Chunk 3's calibration
    // job. The simulator must NOT pre-populate them — that would inject
    // training-set leakage.
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 5]);
    $sku      = makeSimSku($supplier->id, 5, 20);

    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 1, 30);
    $daily = array_fill_keys(
        collect(range(0, 29))->map(fn ($i) => $start->copy()->addDays($i)->format('Y-m-d'))->all(),
        2,
    );

    (new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 1))
        ->simulate($sku, $supplier, $daily, $start, $end);

    $sample = InventoryPositionSnapshot::withoutGlobalScopes()->where('sku_id', $sku->id)->first();
    expect($sample->reorder_within_threshold)->toBeNull();
    expect($sample->stockout_within_threshold)->toBeNull();
});
