<?php

use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\InventoryEngine\DemandForecaster;
use Carbon\Carbon;

it('returns demand_rate from registry when entry exists', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'      => 1,
        'sku_id'         => $sku->id,
        'model_name'     => 'holt_winters',
        'demand_rate'    => 3.75,
        'trained_at'     => now(),
        'next_review_at' => now()->addDays(30),
    ]);

    $result = (new DemandForecaster)->forecast($sku->id, 30, 1);

    expect($result->daily_demand)->toBe(3.75);
    expect($result->method)->toBe('holt_winters');
    expect($result->horizon_demand)->toBe(round(3.75 * 30, 4));
});

it('falls back to weighted moving average when no registry entry', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // Seed 5 days of sales at 4 units/day
    $base = Carbon::today()->subDays(5);
    for ($i = 0; $i < 5; $i++) {
        SalesHistory::create([
            'sku_id'        => $sku->id,
            'tenant_id'     => 1,
            'sale_date'     => $base->copy()->addDays($i),
            'quantity_sold' => 4,
            'is_promotion'  => false,
        ]);
    }

    $result = (new DemandForecaster)->forecast($sku->id, 30, 1);

    expect($result->method)->toBe('weighted_moving_average');
    expect($result->daily_demand)->toBe(4.0);
    expect($result->horizon_demand)->toBe(120.0);
});

it('returns no_data when no registry and no sales history', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $result = (new DemandForecaster)->forecast($sku->id, 30, 1);

    expect($result->method)->toBe('no_data');
    expect($result->daily_demand)->toBe(0.0);
});

it('uses registry entry over raw sales data', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // Sales say 10 units/day
    $base = Carbon::today()->subDays(5);
    for ($i = 0; $i < 5; $i++) {
        SalesHistory::create([
            'sku_id'        => $sku->id,
            'tenant_id'     => 1,
            'sale_date'     => $base->copy()->addDays($i),
            'quantity_sold' => 10,
            'is_promotion'  => false,
        ]);
    }

    // Registry says 2.0 (different model result)
    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'      => 1,
        'sku_id'         => $sku->id,
        'model_name'     => 'croston',
        'demand_rate'    => 2.0,
        'trained_at'     => now(),
        'next_review_at' => now()->addDays(30),
    ]);

    $result = (new DemandForecaster)->forecast($sku->id, 30, 1);

    // Registry wins
    expect($result->daily_demand)->toBe(2.0);
    expect($result->method)->toBe('croston');
});
