<?php

use App\Models\SalesHistory;
use App\Models\Sku;
use App\Services\Synthetic\SeaDatasetSeeder;
use App\Services\Synthetic\SeaDemoFeedTopUp;
use App\Support\TenantContext;
use Carbon\Carbon;

beforeEach(function () {
    TenantContext::set(1);
});

afterEach(function () {
    TenantContext::clear();
    Carbon::setTestNow();
});

/**
 * Seed a short window at a frozen "today", then move the clock forward so
 * there is a gap for the top-up to fill. Freezing matters: SeaDatasetSeeder
 * anchors its window to Carbon::today(), so without a fixed clock the setup
 * drifts with the wall clock.
 */
function seedThenAdvance(int $windowDays, string $seedDay, string $advanceTo): void
{
    Carbon::setTestNow($seedDay);
    (new SeaDatasetSeeder(seed: 42, windowDays: $windowDays))->seed();
    Carbon::setTestNow($advanceTo);
}

it('appends rows for the days elapsed since the last recorded sale', function () {
    seedThenAdvance(60, '2026-05-13', '2026-05-27');

    $before = SalesHistory::count();

    $result = (new SeaDemoFeedTopUp(seed: 42))->run();

    expect(SalesHistory::count())->toBeGreaterThan($before)
        ->and($result['rows_written'])->toBeGreaterThan(0)
        ->and($result['skus_topped_up'])->toBeGreaterThan(0)
        ->and($result['through'])->toBe('2026-05-26'); // yesterday relative to frozen now
});

it('never writes past the through date', function () {
    seedThenAdvance(60, '2026-05-13', '2026-05-27');

    (new SeaDemoFeedTopUp(seed: 42))->run();

    $latest = SalesHistory::max('sale_date');

    expect(Carbon::parse($latest)->toDateString())
        ->toBeLessThanOrEqual('2026-05-26');
});

it('leaves stopped_selling SKUs frozen so the dead-stock signal survives', function () {
    seedThenAdvance(60, '2026-05-13', '2026-05-27');

    // SEA-BOO-005 and SEA-BOO-006 carry the stopped_selling pathology.
    $dead     = Sku::where('sku_code', 'SEA-BOO-005')->first();
    $lastBefore = SalesHistory::where('sku_id', $dead->id)->max('sale_date');

    $result = (new SeaDemoFeedTopUp(seed: 42))->run();

    $lastAfter = SalesHistory::where('sku_id', $dead->id)->max('sale_date');

    expect($result['skus_frozen'])->toBe(2)
        ->and($lastAfter)->toBe($lastBefore, 'a stopped-selling SKU must not be resurrected');
});

it('is idempotent — a second run on the same day writes nothing', function () {
    seedThenAdvance(60, '2026-05-13', '2026-05-27');

    (new SeaDemoFeedTopUp(seed: 42))->run();
    $afterFirst = SalesHistory::count();

    $second = (new SeaDemoFeedTopUp(seed: 42))->run();

    expect($second['rows_written'])->toBe(0)
        ->and($second['skus_topped_up'])->toBe(0)
        ->and(SalesHistory::count())->toBe($afterFirst);
});

it('reports every SKU as already current when no time has passed', function () {
    Carbon::setTestNow('2026-05-13');
    (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    $result = (new SeaDemoFeedTopUp(seed: 42))->run();

    expect($result['rows_written'])->toBe(0)
        ->and($result['skus_topped_up'])->toBe(0);
});

it('refuses to run from the command when the config flag is disabled', function () {
    config()->set('synthetic_dataset.feed_topup.enabled', false);

    $this->artisan('demo:topup-sales')
        ->expectsOutputToContain('Demo feed top-up is disabled')
        ->assertExitCode(1);
});

it('runs from the command when explicitly forced', function () {
    config()->set('synthetic_dataset.feed_topup.enabled', false);
    seedThenAdvance(60, '2026-05-13', '2026-05-27');

    $this->artisan('demo:topup-sales', ['--force' => true])
        ->assertExitCode(0);
});
