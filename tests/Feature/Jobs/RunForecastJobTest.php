<?php

use App\Jobs\RunForecastJob;
use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\SkuDemandProfile;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Process;

it('upserts forecast_model_registry on successful Python run', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    // Seed some sales history so the job has data to send
    $date = Carbon::today()->subDays(5);
    for ($i = 0; $i < 5; $i++) {
        SalesHistory::create([
            'sku_id'        => $sku->id,
            'tenant_id'     => 1,
            'sale_date'     => $date->copy()->addDays($i),
            'quantity_sold' => 3,
            'is_promotion'  => false,
        ]);
    }

    $fakeOutput = json_encode([
        'sku_id'                => $sku->id,
        'tenant_id'             => 1,
        'model_name'            => 'ets_fallback',
        'demand_rate'           => 2.5000,
        'forecast_horizon_days' => 30,
        'mae'                   => 0.4,
        'rmse'                  => 0.5,
        'bias'                  => 0.1,
        'smape'                 => 18.5,
        'reeval_trigger'        => 'scheduled',
        'warnings'              => [],
        'trained_at'            => '2026-04-13T10:00:00Z',
        'next_review_at'        => '2026-05-13T10:00:00Z',
        'demand_profile'        => [
            'volume_tier'          => 'medium',
            'volatility'           => 'stable',
            'intermittency'        => 'continuous',
            'seasonality_detected' => false,
            'trend_direction'      => 'flat',
            'history_days_used'    => 5,
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: $fakeOutput, exitCode: 0),
    ]);

    (new RunForecastJob($sku->id, 1, 'scheduled'))->handle();

    $registry = ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->where('tenant_id', 1)
        ->first();

    expect($registry)->not->toBeNull();
    expect($registry->model_name)->toBe('ets_fallback');
    expect($registry->demand_rate)->toBe(2.5);
});

it('upserts sku_demand_profiles on successful Python run', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $fakeOutput = json_encode([
        'sku_id'                => $sku->id,
        'tenant_id'             => 1,
        'model_name'            => 'croston',
        'demand_rate'           => 0.3,
        'forecast_horizon_days' => 30,
        'mae'                   => 0.2,
        'rmse'                  => 0.3,
        'bias'                  => 0.0,
        'smape'                 => 50.0,
        'reeval_trigger'        => 'new_sku',
        'warnings'              => [],
        'trained_at'            => '2026-04-13T10:00:00Z',
        'next_review_at'        => '2026-05-13T10:00:00Z',
        'demand_profile'        => [
            'volume_tier'          => 'low',
            'volatility'           => 'erratic',
            'intermittency'        => 'intermittent',
            'seasonality_detected' => false,
            'trend_direction'      => 'flat',
            'history_days_used'    => 365,
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: $fakeOutput, exitCode: 0),
    ]);

    (new RunForecastJob($sku->id, 1, 'new_sku'))->handle();

    $profile = SkuDemandProfile::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($profile)->not->toBeNull();
    expect($profile->intermittency)->toBe('intermittent');
    expect($profile->volume_tier)->toBe('low');
});

it('overwrites an existing registry entry on re-run', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'   => 1,
        'sku_id'      => $sku->id,
        'model_name'  => 'holt_winters',
        'demand_rate' => 1.0,
        'trained_at'  => now()->subDays(30),
        'next_review_at' => now(),
    ]);

    $fakeOutput = json_encode([
        'sku_id'                => $sku->id,
        'tenant_id'             => 1,
        'model_name'            => 'sarimax',
        'demand_rate'           => 4.2,
        'forecast_horizon_days' => 30,
        'mae'                   => 0.3,
        'rmse'                  => 0.4,
        'bias'                  => -0.05,
        'smape'                 => 12.0,
        'reeval_trigger'        => 'bias_drift',
        'warnings'              => [],
        'trained_at'            => '2026-04-13T10:00:00Z',
        'next_review_at'        => '2026-05-13T10:00:00Z',
        'demand_profile'        => [
            'volume_tier'          => 'medium',
            'volatility'           => 'moderate',
            'intermittency'        => 'continuous',
            'seasonality_detected' => false,
            'trend_direction'      => 'flat',
            'history_days_used'    => 200,
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: $fakeOutput, exitCode: 0),
    ]);

    (new RunForecastJob($sku->id, 1, 'bias_drift'))->handle();

    // Should still be only one entry per SKU
    expect(ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)->count()
    )->toBe(1);

    $registry = ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)->first();

    expect($registry->model_name)->toBe('sarimax');
    expect((float) $registry->demand_rate)->toBe(4.2);
});

it('fails the job when Python exits with non-zero code', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    Process::fake([
        '*' => Process::result(output: '{"error": "fatal"}', exitCode: 1),
    ]);

    $job = new RunForecastJob($sku->id, 1, 'scheduled');

    // The job should throw when Python fails
    expect(fn () => $job->handle())->toThrow(\RuntimeException::class);

    expect(ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)->count()
    )->toBe(0);
});

it('persists interval and selection fields from Python output', function () {
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $fakeOutput = json_encode([
        'sku_id'                        => $sku->id,
        'tenant_id'                     => 1,
        'model_name'                    => 'holt_winters',
        'demand_rate'                   => 3.1200,
        'forecast_horizon_days'         => 30,
        'mae'                           => 0.8,
        'rmse'                          => 1.1,
        'bias'                          => -0.05,
        'smape'                         => 14.2,
        'interval_lower'                => 2.4000,
        'interval_upper'                => 3.8000,
        'interval_confidence'           => 0.95,
        'interval_empirical_coverage'   => 0.923,
        'selection_rationale'           => 'holt_winters beat baseline (CV MAE 0.8 vs 1.2)',
        'transformation_applied'        => 'none',
        'hyperparameters'               => ['alpha' => 0.3, 'beta' => 0.1],
        'reeval_trigger'                => 'scheduled',
        'warnings'                      => [],
        'trained_at'                    => '2026-04-16T10:00:00Z',
        'next_review_at'                => '2026-05-16T10:00:00Z',
        'demand_profile'                => [
            'volume_tier'          => 'medium',
            'volatility'           => 'stable',
            'intermittency'        => 'continuous',
            'seasonality_detected' => false,
            'trend_direction'      => 'flat',
            'history_days_used'    => 180,
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: $fakeOutput, exitCode: 0),
    ]);

    (new RunForecastJob($sku->id, 1, 'scheduled'))->handle();

    $registry = ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($registry->interval_lower)->toBe(2.4);
    expect($registry->interval_upper)->toBe(3.8);
    expect($registry->interval_confidence)->toBe(0.95);
    expect($registry->interval_empirical_coverage)->toBe(0.923);
    expect($registry->selection_rationale)->toBe('holt_winters beat baseline (CV MAE 0.8 vs 1.2)');
    expect($registry->transformation_applied)->toBe('none');
    expect($registry->hyperparameters)->toBe(['alpha' => 0.3, 'beta' => 0.1]);
});

it('persists null metrics when Python could not evaluate (insufficient history)', function () {
    // Regression guard: when the classifier short-circuits to ets_fallback
    // because history < min_history_days, Python returns null for mae/rmse/
    // bias/smape rather than placeholder zeros. The registry must preserve
    // those nulls — writing 0.0 would falsely advertise perfect accuracy in
    // dashboards (e.g. "0% sMAPE" displayed as a green badge).
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $fakeOutput = json_encode([
        'sku_id'                        => $sku->id,
        'tenant_id'                     => 1,
        'model_name'                    => 'ets_fallback',
        'demand_rate'                   => 1.5000,
        'forecast_horizon_days'         => 30,
        'mae'                           => null,
        'rmse'                          => null,
        'bias'                          => null,
        'smape'                         => null,
        'interval_lower'                => null,
        'interval_upper'                => null,
        'interval_confidence'           => 0.95,
        'interval_empirical_coverage'   => null,
        'selection_rationale'           => '',
        'transformation_applied'        => 'none',
        'hyperparameters'               => [],
        'reeval_trigger'                => 'new_sku',
        'warnings'                      => ['Insufficient history — ets_fallback assigned without model competition.'],
        'trained_at'                    => '2026-04-26T10:00:00Z',
        'next_review_at'                => '2026-05-26T10:00:00Z',
        'demand_profile'                => [
            'volume_tier'          => 'low',
            'volatility'           => 'stable',
            'intermittency'        => 'continuous',
            'seasonality_detected' => false,
            'trend_direction'      => 'flat',
            'history_days_used'    => 30,
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: $fakeOutput, exitCode: 0),
    ]);

    (new RunForecastJob($sku->id, 1, 'new_sku'))->handle();

    $registry = ForecastModelRegistry::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($registry->mae)->toBeNull();
    expect($registry->rmse)->toBeNull();
    expect($registry->bias)->toBeNull();
    expect($registry->smape)->toBeNull();
});
