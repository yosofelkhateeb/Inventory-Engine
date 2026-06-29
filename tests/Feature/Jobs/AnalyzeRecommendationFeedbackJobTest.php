<?php

use App\Events\ForecastDriftDetected;
use App\Jobs\AnalyzeRecommendationFeedbackJob;
use App\Models\FeedbackMetric;
use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Event;

it('stores feedback_metrics with correct rates for the 7-day window', function () {
    Event::fake();

    $sku = Sku::factory()->create(['tenant_id' => 1]);

    $base = [
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'order',
        'recommended_qty' => 10,
        'constrained_qty' => 10,
        'reasoning'       => [],
        'forecast_demand' => 1.0,
        'days_of_cover'   => 5.0,
        'reorder_point'   => 5.0,
    ];

    // 2 ignored, 3 pending → ignored_rate = 0.4
    foreach (range(1, 2) as $_) {
        $d = InventoryDecision::create($base);
        \DB::table('inventory_decisions')->where('id', $d->id)->update(['status' => 'ignored', 'created_at' => now()]);
    }
    foreach (range(1, 3) as $_) {
        InventoryDecision::create($base);
        \DB::table('inventory_decisions')->where('id', InventoryDecision::withoutGlobalScopes()->latest('id')->value('id'))->update(['created_at' => now()]);
    }

    (new AnalyzeRecommendationFeedbackJob())->handle();

    $metric = FeedbackMetric::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->ignored_rate)->toBe(0.4)
        ->and($metric->superseded_rate)->toBe(0.0);
});

it('dispatches ForecastDriftDetected when ignored_rate exceeds threshold', function () {
    Event::fake();

    $sku = Sku::factory()->create(['tenant_id' => 1]);

    // Set threshold low so it's easily exceeded
    SystemSetting::withoutGlobalScopes()->create([
        'tenant_id' => 1,
        'key'       => 'feedback.ignored_rate_threshold',
        'value'     => '0.10',
        'group'     => 'forecasting',
    ]);

    $base = [
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'order',
        'recommended_qty' => 10,
        'constrained_qty' => 10,
        'reasoning'       => [],
        'forecast_demand' => 1.0,
        'days_of_cover'   => 5.0,
        'reorder_point'   => 5.0,
    ];

    $d = InventoryDecision::create($base);
    \DB::table('inventory_decisions')->where('id', $d->id)->update(['status' => 'ignored', 'created_at' => now()]);
    InventoryDecision::create($base);

    (new AnalyzeRecommendationFeedbackJob())->handle();

    Event::assertDispatched(ForecastDriftDetected::class, fn ($e) => $e->skuId === $sku->id);
});

it('does not dispatch ForecastDriftDetected when all metrics are within threshold', function () {
    Event::fake();

    $sku = Sku::factory()->create(['tenant_id' => 1]);

    // All decisions are 'pending' (not ignored, superseded, etc.)
    foreach (range(1, 3) as $_) {
        $d = InventoryDecision::create([
            'sku_id'          => $sku->id,
            'run_at'          => now(),
            'decision'        => 'watch',
            'recommended_qty' => 0,
            'constrained_qty' => 0,
            'reasoning'       => [],
            'forecast_demand' => 1.0,
            'days_of_cover'   => 20.0,
            'reorder_point'   => 5.0,
        ]);
        \DB::table('inventory_decisions')->where('id', $d->id)->update(['created_at' => now()]);
    }

    (new AnalyzeRecommendationFeedbackJob())->handle();

    Event::assertNotDispatched(ForecastDriftDetected::class);
});
