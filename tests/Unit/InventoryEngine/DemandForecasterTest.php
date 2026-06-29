<?php

use App\Models\Sku;
use App\Models\SalesHistory;
use App\Services\InventoryEngine\DemandForecaster;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns a forecast result with positive demand', function () {
    $sku = Sku::factory()->create(['lead_time_days' => 7]);
    for ($i = 30; $i >= 1; $i--) {
        SalesHistory::create([
            'sku_id' => $sku->id,
            'sale_date' => Carbon::today()->subDays($i),
            'quantity_sold' => 5,
        ]);
    }
    $result = (new DemandForecaster())->forecast($sku->id, 30);
    expect($result)->toBeInstanceOf(ForecastResult::class)
        ->and($result->daily_demand)->toBeGreaterThan(0)
        ->and($result->demand_stddev)->toBeGreaterThanOrEqual(0)
        ->and($result->horizon_demand)->toBeGreaterThan(0);
});

it('uses moving average for low velocity skus', function () {
    $sku = Sku::factory()->create();
    for ($i = 30; $i >= 1; $i--) {
        SalesHistory::create([
            'sku_id' => $sku->id,
            'sale_date' => Carbon::today()->subDays($i),
            'quantity_sold' => $i % 2 === 0 ? 1 : 0,
        ]);
    }
    $result = (new DemandForecaster())->forecast($sku->id, 30);
    expect($result->daily_demand)->toBeFloat()
        ->and($result->daily_demand)->toBeLessThan(2)
        ->and($result->method)->toBe('weighted_moving_average');
});

it('returns zero demand when no history exists', function () {
    $sku = Sku::factory()->create();
    $result = (new DemandForecaster())->forecast($sku->id, 30);
    expect($result->daily_demand)->toBe(0.0)
        ->and($result->method)->toBe('no_data');
});
