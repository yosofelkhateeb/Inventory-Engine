<?php

use App\Jobs\RunForecastJob;
use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use Carbon\Carbon;
use Database\Seeders\ForecastSettingsSeeder;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\ShopifyFixtureGenerator;
use Tests\Fixtures\ShopifyOrderFactory;

/**
 * Pipeline data-shape coverage.
 *
 * Tier 1 (fast, always runs): fixture-shape assertions — each pathology
 * produces the shape we claim it does. Guards against a refactor silently
 * changing what a pathology means.
 *
 * Tier 2 (slow, python-integration group): one SKU per pathology fed
 * through the real Python pipeline. Asserts the pipeline survives each
 * shape and emits the expected warnings (e.g. trailing_zero_run on
 * stopped_selling). Skipped when Python is unreachable.
 */

// ── Tier 1: Fixture shape assertions ───────────────────────────────────────

it('produces a clean weekly-seasonal series for the clean pathology', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-90 days');

    $orders = $factory->ordersFor('clean', 'CLEAN-1', $from, $to, baseLevel: 10);
    $rows   = $factory->toSalesHistory($orders);

    $totalQty = array_sum(array_column($rows, 'quantity_sold'));
    $mean     = $totalQty / max(count($rows), 1);

    expect(count($rows))->toBeGreaterThan(80);
    expect($mean)->toBeGreaterThan(6.0)->toBeLessThan(14.0);
    expect(collect($rows)->contains('is_promotion', true))->toBeFalse();
});

it('produces mostly-zero quantities for the sparse pathology', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-100 days');

    $orders = $factory->ordersFor('sparse', 'SPARSE-1', $from, $to, baseLevel: 1);
    $rows   = $factory->toSalesHistory($orders);

    // Sparse means ~15% of days have a sale. 100 days → roughly 10-25 rows.
    expect(count($rows))->toBeGreaterThan(5)->toBeLessThan(40);
    foreach ($rows as $r) {
        expect($r['quantity_sold'])->toBeGreaterThan(0)->toBeLessThanOrEqual(3);
    }
});

it('produces stockout gaps in the middle of the series for stockout_gaps', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-120 days');

    $orders = $factory->ordersFor('stockout_gaps', 'STOCK-1', $from, $to, baseLevel: 12);
    $rows   = $factory->toSalesHistory($orders);

    // Must have a long run of missing days somewhere in the middle (not at
    // the very end — that would make it stopped_selling).
    $dates      = array_column($rows, 'sale_date');
    $lastDate   = end($dates);
    expect($lastDate)->toBe($to->format('Y-m-d'));

    // At least one 7+ day gap somewhere internal
    sort($dates);
    $maxGap = 0;
    for ($i = 1; $i < count($dates); $i++) {
        $gap = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
        $maxGap = max($maxGap, (int) $gap);
    }
    expect($maxGap)->toBeGreaterThanOrEqual(8);
});

it('produces discount_codes on promo days for promo_spike', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-120 days');

    $orders     = $factory->ordersFor('promo_spike', 'PROMO-1', $from, $to, baseLevel: 8);
    $promoRows  = array_filter($orders, fn ($o) => ! empty($o['discount_codes']));
    $normalRows = array_filter($orders, fn ($o) => empty($o['discount_codes']));

    expect(count($promoRows))->toBeGreaterThanOrEqual(10);                  // 3 windows × 5 days
    expect(count($promoRows))->toBeLessThan(count($normalRows));            // mostly normal days

    // Promo day quantity > normal day quantity (the spike)
    $promoQty  = array_sum(array_map(fn ($o) => $o['line_items'][0]['quantity'], $promoRows));
    $normalQty = array_sum(array_map(fn ($o) => $o['line_items'][0]['quantity'], $normalRows));
    $promoMean = $promoQty / max(count($promoRows), 1);
    $normMean  = $normalQty / max(count($normalRows), 1);
    expect($promoMean)->toBeGreaterThan($normMean * 1.5);
});

it('produces refund events that reduce net quantity for returns_heavy', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-120 days');

    $orders  = $factory->ordersFor('returns_heavy', 'RET-1', $from, $to, baseLevel: 10);
    $refunded = array_filter($orders, fn ($o) => ! empty($o['refunds']));

    // At least some refunds exist (~15% rate)
    expect(count($refunded))->toBeGreaterThanOrEqual(5);

    // Refund structure matches Shopify shape
    $first = array_values($refunded)[0];
    expect($first['refunds'][0])->toHaveKey('refund_line_items');
    expect($first['refunds'][0]['refund_line_items'][0])->toHaveKeys(['line_item_id', 'quantity', 'restock_type']);

    // Net quantity (after refund) < gross
    $rows    = $factory->toSalesHistory($orders);
    $netQty  = array_sum(array_column($rows, 'quantity_sold'));
    $grossQty = array_sum(array_map(fn ($o) => $o['line_items'][0]['quantity'], $orders));
    expect($netQty)->toBeLessThan($grossQty);
});

it('zeros out the last ~40% of the series for stopped_selling', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-120 days');

    $orders = $factory->ordersFor('stopped_selling', 'STOPPED-1', $from, $to, baseLevel: 15);
    $rows   = $factory->toSalesHistory($orders);

    $lastDate = max(array_column($rows, 'sale_date'));

    // Last sale should be well before the series end — enough trailing zero
    // days to trip the trailing_zero_run audit warning (threshold 3 days).
    // We seed 40% of 120 days = 48 dead days at the tail.
    $latestAllowed = $to->modify('-30 days')->format('Y-m-d');
    expect($lastDate)->toBeLessThanOrEqual($latestAllowed);
});

it('produces only the last 30 days of history for new_sku', function () {
    $factory = new ShopifyOrderFactory(seed: 42);
    $to      = new \DateTimeImmutable('2026-04-20');
    $from    = $to->modify('-120 days');

    $orders = $factory->ordersFor('new_sku', 'NEW-1', $from, $to, baseLevel: 10);
    $rows   = $factory->toSalesHistory($orders);

    $firstSaleDate = min(array_column($rows, 'sale_date'));
    $earliestAllowed = $to->modify('-30 days')->format('Y-m-d');

    expect($firstSaleDate)->toBeGreaterThanOrEqual($earliestAllowed);
});

it('is deterministic — same seed, same SKU, same output', function () {
    $to   = new \DateTimeImmutable('2026-04-20');
    $from = $to->modify('-30 days');

    $a = (new ShopifyOrderFactory(seed: 99))->ordersFor('clean', 'DET-1', $from, $to, 10);
    $b = (new ShopifyOrderFactory(seed: 99))->ordersFor('clean', 'DET-1', $from, $to, 10);

    expect(json_encode($a))->toBe(json_encode($b));
});

it('generator assigns all requested SKUs across pathologies', function () {
    $generator = new ShopifyFixtureGenerator(seed: 7, tenantId: 1);

    Supplier::factory()->create(['tenant_id' => 1]);

    $summary = $generator->generate(skuCount: 30, historyDays: 120);

    expect(count($summary))->toBe(30);

    $byPathology = collect($summary)->countBy('pathology');
    foreach (ShopifyOrderFactory::PATHOLOGIES as $p) {
        expect($byPathology->get($p, 0))->toBeGreaterThan(0, "pathology '{$p}' got zero SKUs");
    }

    // SalesHistory rows actually written
    $skuIds = array_column($summary, 'sku_id');
    expect(SalesHistory::withoutGlobalScopes()->whereIn('sku_id', $skuIds)->count())
        ->toBeGreaterThan(500);
});

// ── Tier 2: Real-pipeline integration per pathology (slow) ─────────────────

it('pipeline survives each pathology and emits the expected warnings', function () {
    $pythonBin = config('forecasting.python_bin', 'python');
    $probe     = Process::run([$pythonBin, '-c', 'print("ok")']);
    if (! $probe->successful()) {
        $this->markTestSkipped("Python binary '{$pythonBin}' not reachable");
    }

    (new ForecastSettingsSeeder())->run();

    $generator = new ShopifyFixtureGenerator(seed: 13, tenantId: 1);

    // One SKU per pathology — weight 1 each, skuCount == pathology count.
    $mix     = array_fill_keys(ShopifyOrderFactory::PATHOLOGIES, 1);
    $count   = count($mix);
    $summary = $generator->generate(skuCount: $count, historyDays: 365, pathologyMix: $mix);

    foreach ($summary as $a) {
        (new RunForecastJob($a['sku_id'], 1, 'manual'))->handle();

        $reg = ForecastModelRegistry::withoutGlobalScopes()
            ->where('sku_id', $a['sku_id'])
            ->first();

        expect($reg)->not->toBeNull("No registry row produced for {$a['pathology']}");

        $warnings = collect($reg->warnings ?? []);

        // Stale-feed two-tier behaviour. Different pathologies have
        // different expected outcomes — encode them precisely so the test
        // catches real regressions without over-asserting on shapes whose
        // trailing gap is naturally variable (sparse, new_sku).
        $criticalHit = $warnings->first(
            fn ($w) => is_string($w) && str_starts_with($w, 'stale_feed_critical')
        );
        $unusualHit = $warnings->first(
            fn ($w) => is_string($w) && str_starts_with($w, 'stale_feed:')
        );

        if ($a['pathology'] === 'stopped_selling') {
            // ~146 days of trailing dead time blows past the 60-day ceiling.
            // Critical tier MUST fire.
            expect($criticalHit)->not->toBeNull(
                "stopped_selling should trip stale_feed_critical (got: " . json_encode($warnings->all()) . ")"
            );
        } elseif (in_array($a['pathology'], ['clean', 'promo_spike', 'stockout_gaps', 'returns_heavy'], true)) {
            // These shapes have dense sales near the end of the window
            // (every day or near-daily), so the trailing gap is 1-2 days.
            // Far below their own adaptive p95. NEITHER tier should fire.
            // This is the actual fix we are guarding — these were the
            // SKUs producing alarm-fatigue noise on every benchmark run.
            expect($criticalHit)->toBeNull("{$a['pathology']} should not fire stale_feed_critical");
            expect($unusualHit)->toBeNull(
                "{$a['pathology']} has dense recent sales — should NOT fire stale_feed (got: " . json_encode($unusualHit) . ")"
            );
        } else {
            // sparse / new_sku: pathologies whose trailing-gap is random by
            // design. They may legitimately exceed their own p95 threshold
            // (that IS what p95 means — top 5% of gaps will breach it).
            // We only assert the loud tier doesn't fire — these shapes are
            // never genuinely "broken-feed" stale.
            expect($criticalHit)->toBeNull(
                "{$a['pathology']} should not trip stale_feed_critical (got: " . json_encode($criticalHit) . ")"
            );
        }

        // Note: we do NOT assert sanity_ceiling_breach never fires — for
        // genuinely hard pathologies (sparse intermittent demand, new SKUs
        // with <30d of data), an 80%+ sMAPE is a legitimate guardrail
        // outcome, not a regression. This test verifies survivability of
        // each shape; unforecastability is a separate concern surfaced via
        // the warning itself.

        // Statistical winners (SARIMAX, Holt-Winters) must produce non-degenerate
        // intervals. A regression we hit in 2026-04: SARIMAX's .conf_int() returns
        // an ndarray (not DataFrame) when fed numpy input, so ci.iloc[:,0] raised
        // AttributeError inside an inner try/except, the fallback passed an empty
        // train_series, and coverage silently collapsed to 0 for every promo_spike
        // SKU. This assertion kills that class of bug.
        if (in_array($reg->model_name, ['sarimax', 'holt_winters'], true)) {
            expect((float) $reg->interval_upper)->toBeGreaterThan(
                (float) $reg->interval_lower,
                "{$a['pathology']} ({$reg->model_name}): interval bounds degenerate — upper={$reg->interval_upper}, lower={$reg->interval_lower}"
            );
            expect((float) ($reg->interval_empirical_coverage ?? 0))->toBeGreaterThan(
                0.0,
                "{$a['pathology']} ({$reg->model_name}): interval_empirical_coverage=0 indicates silent failure in interval generation"
            );
        }
    }
})->group('slow', 'python-integration');
