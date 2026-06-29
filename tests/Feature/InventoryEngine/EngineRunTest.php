<?php

use App\Models\EngineRun;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SalesHistory;
use App\Services\InventoryEngine\InventoryEngineService;
use Carbon\Carbon;

it('runs engine for all skus and stores decisions', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);
    $sku = Sku::factory()->create([
        'supplier_id'   => $supplier->id,
        'current_stock' => 10,
        'moq'           => 6,
        'unit_cost'     => 1000,
        'reorder_qty'   => 24,
    ]);

    for ($i = 30; $i >= 1; $i--) {
        SalesHistory::create([
            'sku_id'        => $sku->id,
            'sale_date'     => Carbon::today()->subDays($i),
            'quantity_sold' => 3,
        ]);
    }

    $service = app(InventoryEngineService::class);
    $results = $service->run(budgetRemainingHalalas: 99999900);

    expect($results)->toHaveCount(1)
        ->and($results[0]->decision)->toBeIn(['order', 'watch', 'hold', 'order_budget_blocked']);

    // Persisted to DB
    expect(\App\Models\InventoryDecision::count())->toBe(1);

    expect(EngineRun::count())->toBe(1);
    expect(EngineRun::first()->status)->toBe('completed');
    expect(EngineRun::first()->decisions_count)->toBeGreaterThan(0);
});
