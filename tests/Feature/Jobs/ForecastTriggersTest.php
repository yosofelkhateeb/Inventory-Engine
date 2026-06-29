<?php

use App\Jobs\CheckBiasDriftJob;
use App\Jobs\RunForecastJob;
use App\Models\ForecastModelRegistry;
use App\Models\InventoryDecision;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

// ─── Trigger 3: New SKU observer ─────────────────────────────────────────────

it('dispatches RunForecastJob with new_sku trigger when a SKU is created', function () {
    Queue::fake(); // Reset inherited fake; start fresh capture

    $supplier = Supplier::factory()->create();
    Sku::factory()->create(['supplier_id' => $supplier->id]);

    Queue::assertPushed(RunForecastJob::class, function ($job) {
        return $job->reevalTrigger === 'new_sku';
    });
});

it('does not dispatch on sku update, only on create', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    Queue::fake(); // Reset — clear the new_sku dispatch from create above

    $sku->update(['current_stock' => 999]);

    Queue::assertNothingPushed();
});

// ─── Trigger 4: forecast:sweep command ────────────────────────────────────────

it('dispatches one RunForecastJob per sku when forecast:sweep runs', function () {
    $supplier = Supplier::factory()->create();
    Sku::factory()->count(3)->create(['supplier_id' => $supplier->id]);

    Queue::fake(); // Reset — clear observer dispatches from the factory above

    $this->artisan('forecast:sweep', ['--tenant' => 1])
        ->assertSuccessful();

    Queue::assertPushed(RunForecastJob::class, 3);
});

it('forecast:sweep dispatches with scheduled trigger', function () {
    $supplier = Supplier::factory()->create();
    Sku::factory()->create(['supplier_id' => $supplier->id]);

    Queue::fake(); // Reset

    $this->artisan('forecast:sweep', ['--tenant' => 1]);

    Queue::assertPushed(RunForecastJob::class, function ($job) {
        return $job->reevalTrigger === 'scheduled';
    });
});

it('forecast:sweep with no skus outputs info and exits successfully', function () {
    Queue::fake();

    $this->artisan('forecast:sweep', ['--tenant' => 99])
        ->assertSuccessful()
        ->expectsOutput('No active SKUs found.');

    Queue::assertNothingPushed();
});

// ─── Trigger 2: Bias drift ────────────────────────────────────────────────────

it('dispatches RunForecastJob with bias_drift trigger when forecast is far from actuals', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'      => 1,
        'sku_id'         => $sku->id,
        'model_name'     => 'ets_fallback',
        'demand_rate'    => 5.0,
        'trained_at'     => now()->subDays(5),
        'next_review_at' => now()->addDays(25),
    ]);

    // Persist a decision with forecast_demand=5.0 so CheckBiasDriftJob has something to compare against.
    InventoryDecision::create([
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'order',
        'recommended_qty' => 10,
        'constrained_qty' => 10,
        'reasoning'       => [],
        'forecast_demand' => 5.0,
        'days_of_cover'   => 5.0,
        'reorder_point'   => 5.0,
    ]);

    // Actual sales: 1 unit/day for 30 days (5x lower than forecast → 400% bias)
    $since = Carbon::today()->subDays(30);
    for ($i = 0; $i < 30; $i++) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => $since->copy()->addDays($i),
            'quantity_sold' => 1,
            'is_promotion'  => false,
        ]);
    }

    SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'forecast.bias_drift_threshold_pct'],
        ['value' => '15', 'group' => 'forecasting'],
    );

    Queue::fake(); // Reset before the bias-drift job

    // CheckBiasDriftJob: actual_avg=1.0, bias=(5.0-1.0)/1.0×100=400% > 15% → dispatch
    (new CheckBiasDriftJob())->handle();

    Queue::assertPushed(RunForecastJob::class, function ($job) use ($sku) {
        return $job->skuId === $sku->id
            && $job->reevalTrigger === 'bias_drift';
    });
});

it('does not dispatch bias_drift job when forecast is within threshold', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // Registry: demand_rate=4.1, actual: 4.0/day → bias = 2.5% < 15%
    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'      => 1,
        'sku_id'         => $sku->id,
        'model_name'     => 'holt_winters',
        'demand_rate'    => 4.1,
        'trained_at'     => now()->subDays(5),
        'next_review_at' => now()->addDays(25),
    ]);

    InventoryDecision::create([
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'hold',
        'recommended_qty' => 0,
        'constrained_qty' => 0,
        'reasoning'       => [],
        'forecast_demand' => 4.1,
        'days_of_cover'   => 30.0,
        'reorder_point'   => 10.0,
    ]);

    $since = Carbon::today()->subDays(30);
    for ($i = 0; $i < 30; $i++) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => $since->copy()->addDays($i),
            'quantity_sold' => 4,
            'is_promotion'  => false,
        ]);
    }

    SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'forecast.bias_drift_threshold_pct'],
        ['value' => '15', 'group' => 'forecasting'],
    );

    Queue::fake(); // Reset

    (new CheckBiasDriftJob())->handle();

    Queue::assertNotPushed(RunForecastJob::class, function ($job) {
        return $job->reevalTrigger === 'bias_drift';
    });
});
