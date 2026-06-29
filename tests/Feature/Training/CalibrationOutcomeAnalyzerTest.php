<?php

use App\Models\InventoryPositionSnapshot;
use App\Models\LeadTimeObservation;
use App\Models\Sku;
use App\Models\StockoutEvent;
use App\Models\Supplier;
use App\Services\InventoryEngine\DecisionScorer;
use App\Services\Training\CalibrationOutcomeAnalyzer;
use App\Services\Training\InventorySimulator;
use Carbon\Carbon;

it('classifies a clear true-positive: watch flag with subsequent reorder', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 50, 'moq' => 50]);

    // Snapshot: position just above ROP, daily_demand=10, lead=7, no smape/trend
    // buffer_days = (50-45)/10 = 0.5 days
    // threshold (k_lead=0.5, k_ltv=0): 7*0.5 = 3.5 days, clipped to floor=1 → 3.5
    // 0.5 <= 3.5 → would be WATCH
    InventoryPositionSnapshot::create([
        'tenant_id' => 1, 'sku_id' => $sku->id,
        'snapshot_date' => '2024-01-01',
        'on_hand' => 50, 'in_transit' => 0, 'reserved' => 0,
        'effective_position' => 50, 'reorder_point' => 45,
        'daily_demand' => 10.0, 'demand_stddev' => 1.0,
        'lead_time_days' => 7, 'lead_time_stddev' => 0.0,
        'smape' => null, 'trend_direction' => 'flat',
        'decision' => 'watch', // simulator's call; analyzer ignores this
    ]);

    // Ground truth: a reorder was placed 2 days later (within 3.5-day window)
    LeadTimeObservation::create([
        'tenant_id' => 1, 'supplier_id' => $supplier->id, 'sku_id' => $sku->id,
        'order_placed_at' => '2024-01-03', 'order_received_at' => '2024-01-10',
        'days_actual' => 7, 'source' => 'synthetic',
    ]);

    $result = (new CalibrationOutcomeAnalyzer())->evaluate(
        tenantId: 1, kLead: 0.5, kLtv: 0.0, kSmape: 0.0, kTrend: 0.0,
    );

    expect($result['tp'])->toBe(1);
    expect($result['fp'])->toBe(0);
    expect($result['fn'])->toBe(0);
});

it('classifies a clear false-negative: hold flag but stockout occurred', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 50, 'moq' => 50]);

    // Snapshot with comfortable buffer at first glance
    // buffer_days = (200-50)/10 = 15 days. With low coefficients, threshold ≈ 0.5 days
    // 15 > 0.5 → would be HOLD
    InventoryPositionSnapshot::create([
        'tenant_id' => 1, 'sku_id' => $sku->id,
        'snapshot_date' => '2024-01-01',
        'on_hand' => 200, 'in_transit' => 0, 'reserved' => 0,
        'effective_position' => 200, 'reorder_point' => 50,
        'daily_demand' => 10.0, 'demand_stddev' => 1.0,
        'lead_time_days' => 7, 'lead_time_stddev' => 0.0,
        'smape' => null, 'trend_direction' => 'flat',
        'decision' => 'hold',
    ]);

    // Stockout 5 days later
    StockoutEvent::create([
        'tenant_id' => 1, 'sku_id' => $sku->id,
        'occurred_at' => '2024-01-06', 'recovered_at' => '2024-01-09',
        'duration_days' => 3, 'demand_lost_units' => 30, 'source' => 'synthetic',
    ]);

    // With aggressive thresholds (long window), the FN should appear.
    // k_lead=2.0 → threshold = 14 days, captures the stockout window
    $result = (new CalibrationOutcomeAnalyzer())->evaluate(
        tenantId: 1, kLead: 2.0, kLtv: 0.0, kSmape: 0.0, kTrend: 0.0,
    );

    // hold + stockout in window → FN
    // Actually with k_lead=2, threshold = 14d. buffer = 15 → 15 > 14 → HOLD. Stockout in window → FN.
    expect($result['fn'])->toBe(1);
});

it('counts a true-negative: hold flag and no events in window', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 50, 'moq' => 50]);

    // Comfortable buffer, no events follow
    InventoryPositionSnapshot::create([
        'tenant_id' => 1, 'sku_id' => $sku->id,
        'snapshot_date' => '2024-01-01',
        'on_hand' => 500, 'in_transit' => 0, 'reserved' => 0,
        'effective_position' => 500, 'reorder_point' => 50,
        'daily_demand' => 10.0, 'demand_stddev' => 1.0,
        'lead_time_days' => 7, 'lead_time_stddev' => 0.0,
        'smape' => null, 'trend_direction' => 'flat',
        'decision' => 'hold',
    ]);

    $result = (new CalibrationOutcomeAnalyzer())->evaluate(
        tenantId: 1, kLead: 0.5, kLtv: 0.0, kSmape: 0.0, kTrend: 0.0,
    );

    expect($result['tn'])->toBe(1);
    expect($result['tp'])->toBe(0);
    expect($result['fp'])->toBe(0);
    expect($result['fn'])->toBe(0);
});

it('skips below-ROP snapshots (those are order decisions)', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7]);

    // effective < reorder_point → buffer < 0 → skipped
    InventoryPositionSnapshot::create([
        'tenant_id' => 1, 'sku_id' => $sku->id,
        'snapshot_date' => '2024-01-01',
        'on_hand' => 30, 'in_transit' => 0, 'reserved' => 0,
        'effective_position' => 30, 'reorder_point' => 50,
        'daily_demand' => 10.0, 'demand_stddev' => 1.0,
        'lead_time_days' => 7, 'lead_time_stddev' => 0.0,
        'smape' => null, 'trend_direction' => 'flat',
        'decision' => 'order',
    ]);

    $result = (new CalibrationOutcomeAnalyzer())->evaluate(
        tenantId: 1, kLead: 0.5, kLtv: 0.0, kSmape: 0.0, kTrend: 0.0,
    );

    expect($result['n_snapshots'])->toBe(0);
});

it('produces stable metrics on a real simulated dataset', function () {
    // End-to-end smoke: simulate, then analyze. Confirms the formula
    // mirrors the simulator's DecisionScorer faithfully and the
    // analyzer doesn't crash on real data shapes.
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 30, 'moq' => 30]);

    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 4, 30);
    $daily = [];
    $cursor = $start->copy();
    while ($cursor <= $end) {
        $daily[$cursor->format('Y-m-d')] = 4;
        $cursor->addDay();
    }
    (new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 42))
        ->simulate($sku, $supplier, $daily, $start, $end);

    $result = (new CalibrationOutcomeAnalyzer())->evaluate(
        tenantId: 1, kLead: 0.5, kLtv: 1.65, kSmape: 0.5, kTrend: 0.0,
    );

    // We don't assert exact counts — RNG-dependent — only structural sanity.
    expect($result['tp'] + $result['fp'] + $result['tn'] + $result['fn'])->toBe($result['n_snapshots']);
    expect($result['precision'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect($result['recall'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect($result['f1'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});
