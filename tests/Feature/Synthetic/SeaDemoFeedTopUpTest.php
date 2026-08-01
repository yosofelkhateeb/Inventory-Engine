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

it('does not inflate promo density on promo_spike SKUs in a short window', function () {
    // ShopifyOrderFactory places three fixed 5-day promo blocks at 25/50/75%
    // of any window. Over the 913-day seed that is 1.6% of days; over a short
    // top-up window the same 15 days would be ~half of it, and over a nightly
    // 1-day window every day. That tail is a structural break that pushes
    // model selection onto the baseline, so the top-up generates base demand.
    seedThenAdvance(60, '2026-05-13', '2026-06-14'); // ~32-day gap to fill

    $spiky = Sku::where('sku_code', 'SEA-BND-001')->first(); // promo_spike

    (new SeaDemoFeedTopUp(seed: 42))->run();

    $toppedUp = SalesHistory::where('sku_id', $spiky->id)
        ->where('sale_date', '>', '2026-05-12')
        ->get();

    expect($toppedUp)->not->toBeEmpty();

    $promoDays = $toppedUp->where('is_promotion', true)->count();

    expect($promoDays)->toBe(
        0,
        "topped-up days must carry no synthetic promo spikes (got {$promoDays} of {$toppedUp->count()})",
    );
});

it('keeps the demand level continuous across the top-up join', function () {
    // A level shift at the join reads as a structural break to the
    // forecasting pipeline — the artefact this whole feature exists to avoid.
    seedThenAdvance(120, '2026-05-13', '2026-06-14');

    $clean = Sku::where('sku_code', 'SEA-BOO-001')->first(); // clean pathology

    $preMean = SalesHistory::where('sku_id', $clean->id)
        ->whereBetween('sale_date', ['2026-04-11', '2026-05-12'])
        ->avg('quantity_sold');

    (new SeaDemoFeedTopUp(seed: 42))->run();

    $postMean = SalesHistory::where('sku_id', $clean->id)
        ->where('sale_date', '>', '2026-05-12')
        ->avg('quantity_sold');

    expect($preMean)->toBeGreaterThan(0);

    $ratio = $postMean / $preMean;

    expect($ratio)->toBeGreaterThan(0.6, "level dropped at the join (ratio {$ratio})")
        ->and($ratio)->toBeLessThan(1.7, "level jumped at the join (ratio {$ratio})");
});

it('refuses to top up a pathology it has no rule for', function () {
    // Adding a pathology to the catalogue must be a deliberate decision here.
    // Several factory generators place features at fractions of their window,
    // so an unclassified one would silently produce wrong data every night.
    seedThenAdvance(60, '2026-05-13', '2026-06-14');

    $catalog = require base_path('database/seeders/data/sea_sku_catalog.php');
    $catalog['skus'][0]['pathology'] = 'some_future_pathology';

    expect(fn () => (new SeaDemoFeedTopUp(seed: 42, catalog: $catalog))->run())
        ->toThrow(RuntimeException::class, 'some_future_pathology');
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
