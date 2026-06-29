<?php

use App\Services\InventoryEngine\DecisionScorer;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use App\Services\InventoryEngine\DTOs\InventoryPosition;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makePosition(int $effective, float $daysOfCover): InventoryPosition
{
    return new InventoryPosition($effective, 0, 0, $effective, $daysOfCover);
}

function makeForecast(
    float $daily,
    float $stddev = 1.0,
    ?float $smape = null,
    ?string $trend = null,
): ForecastResult {
    return new ForecastResult($daily, $stddev, $daily * 30, 30, 'moving_average', $smape, $trend);
}

function makeConstraints(bool $budgetBlocked = false, int $qty = 24): ConstrainedQuantity
{
    return new ConstrainedQuantity($qty, $budgetBlocked ? 0 : $qty, $budgetBlocked, []);
}

// ── ORDER / HOLD core path ─────────────────────────────────────────────────

it('returns order when position is at or below reorder point', function () {
    // daily=3, buffered_lead=7, safety = 1.65 * 1.0 * sqrt(7) ≈ 4.37
    // reorder_point = 3*7 + 4.37 ≈ 25.37 → effective=20 → ORDER
    $result = (new DecisionScorer())->score(
        makePosition(20, 6.7),
        makeForecast(3.0),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(),
    );
    expect($result->decision)->toBe('order');
});

it('returns hold when position is well above reorder point and watch threshold', function () {
    // reorder ≈ 25.37, effective=100 → buffer ≈ 24.9 days. With cold-start
    // formula and lead_time=7, smape=null, trend=null:
    //   threshold = 7*0.5 + 1.5*1.65 + 0 + 0 ≈ 5.975 days. 24.9 > 5.975 → HOLD.
    $result = (new DecisionScorer())->score(
        makePosition(100, 33.3),
        makeForecast(3.0),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(),
    );
    expect($result->decision)->toBe('hold');
});

it('returns order_budget_blocked when budget prevents order', function () {
    $result = (new DecisionScorer())->score(
        makePosition(10, 3.3),
        makeForecast(3.0),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(budgetBlocked: true),
    );
    expect($result->decision)->toBe('order_budget_blocked');
});

it('applies safety stock multiplier to reorder point calculation', function () {
    $scorer = new DecisionScorer();
    $position = new InventoryPosition(20, 0, 0, 20, 5.0);
    $forecast = new ForecastResult(2.0, 1.0, 60.0, 30, 'moving_average');
    $leadTime = new LeadTimeEstimate(7, 8, 1.5);
    $constrained = new ConstrainedQuantity(24, 24, false, []);

    $decisionHighMultiplier = $scorer->score($position, $forecast, $leadTime, $constrained, 1.5);
    $decisionDefault        = $scorer->score($position, $forecast, $leadTime, $constrained, 1.0);

    expect($decisionHighMultiplier->safety_stock)->toBeGreaterThan($decisionDefault->safety_stock);
});

// ── WATCH — days-based threshold ──────────────────────────────────────────

it('flags watch when buffer-days falls within the per-SKU threshold', function () {
    // reorder ≈ 25.37, effective=29 → buffer = (29-25.37)/3 ≈ 1.21 days.
    // threshold ≈ 7*0.5 + 1.5*1.65 ≈ 5.975 days. buffer < threshold → WATCH.
    $result = (new DecisionScorer())->score(
        makePosition(29, 9.7),
        makeForecast(3.0),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(),
    );
    expect($result->decision)->toBe('watch');
    expect($result->reasoning)->toHaveKey('buffer_days');
    expect($result->reasoning)->toHaveKey('watch_threshold_days');
});

it('threshold widens when forecast is uncertain (high sMAPE)', function () {
    // Same SKU, but with high sMAPE. Threshold should grow proportionally
    // to lead_time × smape × k_smape.
    // Without sMAPE: threshold ≈ 5.975 days.
    // With sMAPE=50%: extra = 0.5 * 7 * 0.5 = 1.75 → threshold ≈ 7.725 days.
    \App\Models\SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'decision.watch.k_smape'],
        ['value' => '0.5', 'group' => 'forecasting'],
    );
    $result = (new DecisionScorer())->score(
        makePosition(45, 15.0),     // buffer ≈ 6.5 days
        makeForecast(3.0, 1.0, smape: 50.0),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(),
        1.0,
        1, // tenantId
    );
    // 6.5 days falls inside the widened threshold (7.7d) → WATCH.
    expect($result->decision)->toBe('watch');
});

it('threshold widens for upward-trending demand (when k_trend enabled)', function () {
    // k_trend off by default; enable for this test.
    \App\Models\SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'decision.watch.k_trend'],
        ['value' => '2.0', 'group' => 'forecasting'],
    );
    // Without trend: threshold ≈ 5.975. With +1 * 2.0 trend: 7.975.
    $resultUpward = (new DecisionScorer())->score(
        makePosition(45, 15.0),     // buffer ≈ 6.5 days
        makeForecast(3.0, 1.0, trend: 'upward'),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(),
        1.0,
        1,
    );
    expect($resultUpward->decision)->toBe('watch');

    // Same buffer, declining trend → threshold shrinks: 5.975 - 2.0 = 3.975.
    // 6.5 days > 3.975 → HOLD.
    $resultDeclining = (new DecisionScorer())->score(
        makePosition(45, 15.0),
        makeForecast(3.0, 1.0, trend: 'declining'),
        new LeadTimeEstimate(7, 7, 1.5),
        makeConstraints(),
        1.0,
        1,
    );
    expect($resultDeclining->decision)->toBe('hold');
});

it('respects min_floor — never zero buffer days for instant-supply SKUs', function () {
    // 1-day lead time, low variance → raw threshold could collapse below 1.
    // min_floor of 1 should kick in.
    $result = (new DecisionScorer())->score(
        makePosition(20, 6.7),    // effective just above ROP
        makeForecast(3.0, 0.5),
        new LeadTimeEstimate(1, 1, 0.2),
        makeConstraints(),
        1.0,
        1,
    );
    expect($result->reasoning['watch_threshold_days'])->toBeGreaterThanOrEqual(1.0);
});

it('respects per-SKU ceiling (proxy: 2× lead_time_mean)', function () {
    // Long lead time (60d), low stddev so ROP stays modest. With jacked-up
    // k_smape the *raw* threshold would exceed 60d → per-SKU ceiling
    // (2*60=120) and global ceiling (90) cap it. Effective cap = 90 days.
    \App\Models\SystemSetting::firstOrCreate(
        ['tenant_id' => 1, 'key' => 'decision.watch.k_smape'],
        ['value' => '5.0', 'group' => 'forecasting'],   // jacked-up to force ceiling
    );
    // ROP ≈ 3*60 + 1.65*0.5*sqrt(60) ≈ 180 + 6.4 ≈ 186. Position must be > ROP
    // for the watch path to be reached.
    $result = (new DecisionScorer())->score(
        makePosition(800, 266.7),
        makeForecast(3.0, 0.5, smape: 90.0),
        new LeadTimeEstimate(60, 60, 1.0),
        makeConstraints(),
        1.0,
        1,
    );
    expect($result->reasoning)->toHaveKey('watch_threshold_days');
    expect($result->reasoning['watch_threshold_days'])->toBeLessThanOrEqual(90.0);
});

it('holds when daily_demand is zero regardless of buffer', function () {
    // No-demand SKU: any positive buffer is comfortable.
    $result = (new DecisionScorer())->score(
        makePosition(50, 0.0),
        makeForecast(0.0, 0.0),
        new LeadTimeEstimate(7, 7, 1.0),
        makeConstraints(),
    );
    expect($result->decision)->toBe('hold');
});

it('order trumps watch when below ROP, even with zero demand calc', function () {
    // Below ROP path runs before the no-demand short-circuit.
    $result = (new DecisionScorer())->score(
        makePosition(0, 0.0),
        makeForecast(0.0, 0.0),
        new LeadTimeEstimate(7, 7, 1.0),
        makeConstraints(),
    );
    expect($result->decision)->toBe('order');
});
