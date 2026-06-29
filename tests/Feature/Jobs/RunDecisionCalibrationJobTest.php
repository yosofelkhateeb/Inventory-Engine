<?php

use App\Jobs\RunDecisionCalibrationJob;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Services\InventoryEngine\DecisionScorer;
use App\Services\Training\InventorySimulator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

it('writes optimal coefficients to system_settings after calibration', function () {
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 30, 'moq' => 30]);

    // Seed a small simulated dataset so the calibrator has something to fit
    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 3, 31);
    $daily = [];
    $cursor = $start->copy();
    while ($cursor <= $end) {
        $daily[$cursor->format('Y-m-d')] = 4;
        $cursor->addDay();
    }
    (new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 7))
        ->simulate($sku, $supplier, $daily, $start, $end);

    // Run calibration synchronously. Use a tiny grid via subclass / monkey-patch?
    // Easier: just run the production grid — slow but bounded (3-month dataset
    // is fast enough to evaluate the full default grid).
    // Actually default grid is 625 candidates; that's too slow for a test.
    // Use the job, but the analyzer will be quick on this small dataset.
    // Each evaluation is O(snapshots) — ~90 snapshots → milliseconds per eval.
    (new RunDecisionCalibrationJob(tenantId: 1, objective: 'f2'))->handle();

    // Settings should now reflect the optimum
    $kLead = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.k_lead')->value('value');
    $kLtv  = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.k_ltv')->value('value');
    $kSmape = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.k_smape')->value('value');
    $kTrend = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.k_trend')->value('value');

    expect($kLead)->not->toBeNull();
    expect($kLtv)->not->toBeNull();
    expect($kSmape)->not->toBeNull();
    expect($kTrend)->not->toBeNull();

    // Audit fields recorded
    $calibratedAt = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.calibrated_at')->value('value');
    $score        = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.calibration_score')->value('value');
    $objective    = SystemSetting::where('tenant_id', 1)->where('key', 'decision.watch.calibration_objective')->value('value');

    expect($calibratedAt)->not->toBeNull();
    expect((float) $score)->toBeGreaterThanOrEqual(0.0);
    expect($objective)->toBe('f2');
});

it('queues the job to the forecasting queue', function () {
    $job = new RunDecisionCalibrationJob(tenantId: 1);
    expect($job->queue)->toBe('forecasting');
});

it('uniqueId is stable per tenant', function () {
    $j1 = new RunDecisionCalibrationJob(tenantId: 1);
    $j2 = new RunDecisionCalibrationJob(tenantId: 1);
    $j3 = new RunDecisionCalibrationJob(tenantId: 2);
    expect($j1->uniqueId())->toBe($j2->uniqueId());
    expect($j1->uniqueId())->not->toBe($j3->uniqueId());
});

it('emits a drift warning when the new score regresses past the threshold', function () {
    // Pre-seed previous_score = 1.0 (absolute maximum any F1/F2 can hit)
    // and threshold = 0% so any non-perfect score on the new run trips
    // drift. Robust against the tiny test dataset's RNG-driven score.
    SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'decision.watch.calibration_score'],
        ['value' => '1.0', 'group' => 'decision_calibration'],
    );
    SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'decision.watch.calibration_drift_threshold_pct'],
        ['value' => '0.0', 'group' => 'forecasting'],
    );

    // Minimal dataset so the calibrator has something to fit
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7]);
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku->update(['lead_time_days' => 7, 'reorder_qty' => 30, 'moq' => 30]);

    $start = Carbon::create(2024, 1, 1);
    $end   = Carbon::create(2024, 2, 28);
    $daily = [];
    $cursor = $start->copy();
    while ($cursor <= $end) {
        $daily[$cursor->format('Y-m-d')] = 4;
        $cursor->addDay();
    }
    (new InventorySimulator(new DecisionScorer(), tenantId: 1, seed: 17))
        ->simulate($sku, $supplier, $daily, $start, $end);

    Log::shouldReceive('channel')->with('forecasting')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('warning')
        ->withArgs(fn ($msg, $ctx) => $msg === 'decision_calibration_drift_detected')
        ->atLeast()
        ->once();

    (new RunDecisionCalibrationJob(tenantId: 1, objective: 'f2'))->handle();
});
