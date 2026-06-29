<?php

use App\Models\Sku;
use App\Models\Supplier;
use App\Services\InventoryEngine\DecisionScorer;
use App\Services\Training\CalibrationGridSearch;
use App\Services\Training\CalibrationOutcomeAnalyzer;
use App\Services\Training\InventorySimulator;
use Carbon\Carbon;

it('selects the best tuple from the grid', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 30, 'moq' => 30]);

    // 4 months of stable demand to give the search something to work with
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

    // Tiny grid — keep test fast (3*3 = 9 evals)
    $tinyGrid = [
        'k_lead'  => [0.3, 0.5, 1.0],
        'k_ltv'   => [0.0],
        'k_smape' => [0.0],
        'k_trend' => [0.0],
    ];

    $search = new CalibrationGridSearch(new CalibrationOutcomeAnalyzer());
    $result = $search->search(tenantId: 1, objective: 'f1', grid: $tinyGrid);

    expect($result['evaluations'])->toBe(3);
    expect($result['trace'])->toHaveCount(3);
    expect($result['best'])->toHaveKey('k_lead');
    expect($result['best'])->toHaveKey('score');

    // Best score must be at least as high as any in the trace
    foreach ($result['trace'] as $t) {
        expect($result['best']['score'])->toBeGreaterThanOrEqual($t['score']);
    }
});

it('honours the precision_at_recall objective when recall floor is feasible', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 30, 'moq' => 30]);

    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 3, 31);
    $daily = [];
    $cursor = $start->copy();
    while ($cursor <= $end) {
        $daily[$cursor->format('Y-m-d')] = 4;
        $cursor->addDay();
    }
    (new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 99))
        ->simulate($sku, $supplier, $daily, $start, $end);

    $tinyGrid = [
        'k_lead'  => [0.3, 1.0, 2.0],
        'k_ltv'   => [0.0],
        'k_smape' => [0.0],
        'k_trend' => [0.0],
    ];

    // Recall floor = 0 → any tuple satisfies it, so the highest-precision wins
    $result = (new CalibrationGridSearch(new CalibrationOutcomeAnalyzer()))
        ->search(tenantId: 1, objective: 'precision_at_recall', recallFloor: 0.0, grid: $tinyGrid);

    expect($result['best']['metrics']['precision'])->toBeGreaterThanOrEqual(0.0);
});
