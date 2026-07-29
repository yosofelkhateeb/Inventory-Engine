<?php

use App\Models\Promotion;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\Synthetic\SeaDatasetSeeder;
use App\Support\TenantContext;
use Carbon\Carbon;

beforeEach(function () {
    TenantContext::set(1);
});

afterEach(function () {
    TenantContext::clear();
});

it('creates 5 suppliers and 30 SKUs from the catalog', function () {
    $result = (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    expect($result['suppliers_created'])->toBe(5)
        ->and($result['skus_created'])->toBe(30)
        ->and(Supplier::count())->toBe(5)
        ->and(Sku::count())->toBe(30);
});

it('sets opening current_stock to round-to-nearest-10(1.2 × base_level × lead_time)', function () {
    (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    // Pick a known SKU from the catalog and recompute its expected opening stock.
    // SEA-BOO-001 base_level=22, supplier=international(28d).
    // Expected: round(1.2 * 22 * 28 / 10) * 10 = round(73.92) * 10 = 740.
    $sku = Sku::where('sku_code', 'SEA-BOO-001')->first();
    expect($sku)->not->toBeNull()
        ->and($sku->current_stock)->toBe(740)
        ->and($sku->in_transit_qty)->toBe(0)
        ->and($sku->reserved_qty)->toBe(0)
        ->and($sku->lead_time_days)->toBe(28);

    // Spot-check another: SEA-GRP-001 base_level=12, supplier=fast_local_a(5d).
    // Expected: round(1.2 * 12 * 5 / 10) * 10 = round(7.2) * 10 = 70.
    $gripSock = Sku::where('sku_code', 'SEA-GRP-001')->first();
    expect($gripSock->current_stock)->toBe(70)
        ->and($gripSock->lead_time_days)->toBe(5);
});

it('writes SalesHistory rows for every SKU over the window', function () {
    (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    // Each SKU should produce at least some rows (sparse SKUs have ~15% nonzero,
    // so over 60 days that's ~9 rows; clean has near-daily).
    foreach (Sku::all() as $sku) {
        $rowCount = SalesHistory::where('sku_id', $sku->id)->count();
        // Allow zero for very-sparse new_sku pathology, but assert most SKUs have rows.
        if ($rowCount === 0) {
            // Acceptable for stopped_selling tail-end windows — assert no more than 2 SKUs have zero rows.
            // We collect zero-row SKUs at the end of the test to assert in bulk.
        }
    }

    $zeroRowSkus = Sku::all()->filter(
        fn ($s) => SalesHistory::where('sku_id', $s->id)->count() === 0
    )->count();

    expect($zeroRowSkus)->toBeLessThanOrEqual(2)
        ->and(SalesHistory::count())->toBeGreaterThan(500);
});

it('writes Brief-tagged promotions via the campaign generator and reports counts', function () {
    $result = (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    // Campaign generator runs over the FULL 30-month calendar regardless of
    // seeder windowDays — its anchored events are absolute dates.
    expect($result['promos_created'])->toBeGreaterThanOrEqual(50)
        ->and(Promotion::count())->toBe($result['promos_created']);
});

it('is deterministic — same seed produces identical SKU + supplier + row counts', function () {
    $first = (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    // Wipe and re-run.
    Promotion::query()->forceDelete();
    SalesHistory::query()->delete();
    Sku::query()->forceDelete();
    Supplier::query()->forceDelete();

    $second = (new SeaDatasetSeeder(seed: 42, windowDays: 60))->seed();

    expect($second['suppliers_created'])->toBe($first['suppliers_created'])
        ->and($second['skus_created'])->toBe($first['skus_created'])
        ->and($second['sales_rows_written'])->toBe($first['sales_rows_written'])
        ->and($second['promos_created'])->toBe($first['promos_created']);
});

it('applies SEA seasonal multipliers — mega 11.11 day spikes well above the SKU median', function () {
    // SeaDatasetSeeder anchors its window to "today" (end = yesterday,
    // start = end - windowDays). Freeze the clock so the window is
    // deterministic: without this the test quietly stops covering
    // 2024-11-11 once the wall clock passes 2026-07-04, and then fails
    // for good. Laravel resets setTestNow() in tearDown.
    Carbon::setTestNow('2026-05-13');

    // Frozen today (2026-05-13) - 600d = 2024-09-19, so windowDays=600
    // covers 2024-11-11 (mega anchor 3.0×).
    (new SeaDatasetSeeder(seed: 42, windowDays: 600))->seed();

    // Pick a high-volume "clean" SKU so the multiplier effect is visible above noise.
    $cleanSku = Sku::where('sku_code', 'SEA-BOO-004')->first(); // clean, base_level 24
    expect($cleanSku)->not->toBeNull();

    $megaDay = SalesHistory::where('sku_id', $cleanSku->id)
        ->whereDate('sale_date', '2024-11-11')
        ->value('quantity_sold');

    // Compare against the SKU's median over the full seeded window — robust
    // against any single non-event day accidentally falling in another
    // campaign window. With a 3.0× mega multiplier the 11.11 value should
    // be at least 2× the median.
    $allQtys = SalesHistory::where('sku_id', $cleanSku->id)
        ->orderBy('quantity_sold')
        ->pluck('quantity_sold')
        ->all();
    expect(count($allQtys))->toBeGreaterThan(100);
    $median = $allQtys[(int) (count($allQtys) / 2)];

    expect($megaDay)->not->toBeNull()
        ->and($megaDay)->toBeGreaterThan(2 * $median,
            "Mega 11.11 day quantity ({$megaDay}) should be >2× the SKU median ({$median})");
});
