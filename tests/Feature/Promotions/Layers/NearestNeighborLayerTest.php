<?php

use App\Models\Promotion;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\Promotions\BriefVectorizer;
use App\Services\Promotions\Layers\NearestNeighborLayer;
use App\Support\TenantContext;
use Carbon\Carbon;

/**
 * Build a past completed promotion with the supplied Brief fields,
 * attached to its OWN SKU. Each promo isolated to its own SKU so seeded
 * sales windows don't bleed into each other's baseline lookups.
 *
 * Returns [Promotion, Sku] tuple.
 *
 * @param array<string,mixed> $brief
 */
function makePastPromoOnNewSku(array $brief, int $startOffsetDays = 60, int $endOffsetDays = 55): array
{
    $supplier = Supplier::factory()->create(['tenant_id' => 1]);
    $sku      = Sku::factory()->create(['tenant_id' => 1, 'supplier_id' => $supplier->id]);

    $promo = Promotion::create(array_merge([
        'tenant_id'           => 1,
        'name'                => 'Past promo ' . uniqid(),
        'start_date'          => Carbon::today()->subDays($startOffsetDays),
        'end_date'            => Carbon::today()->subDays($endOffsetDays),
        'expected_uplift_pct' => 30,
        'affects_all_skus'    => false,
    ], $brief));
    $promo->skus()->attach($sku->id);

    return [$promo, $sku];
}

/**
 * Seed sales history for one SKU around its dedicated promo window.
 * Baseline window: 30 days before $startOffset (days [$startOffset+1 .. $startOffset+30] ago).
 * During window: from $startOffset to $endOffset (days [$endOffset .. $startOffset] ago).
 */
function seedSalesAroundPromo(Sku $sku, int $startOffset, int $endOffset, float $baselinePerDay, float $duringPerDay): void
{
    for ($i = $startOffset + 1; $i <= $startOffset + 30; $i++) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => Carbon::today()->subDays($i)->toDateString(),
            'quantity_sold' => $baselinePerDay,
        ]);
    }
    for ($i = $endOffset; $i <= $startOffset; $i++) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => Carbon::today()->subDays($i)->toDateString(),
            'quantity_sold' => $duringPerDay,
        ]);
    }
}

beforeEach(function () {
    TenantContext::set(1);
});

afterEach(function () {
    TenantContext::clear();
});

it('returns sample_size 0 when there are no past promotions in the window', function () {
    $result = (new NearestNeighborLayer(new BriefVectorizer))->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
    ]);

    expect($result['sample_size'])->toBe(0)
        ->and($result['value'])->toBe(0.0)
        ->and($result['layer'])->toBe('nearest_neighbor');
});

it('returns the median lift across matched neighbors', function () {
    // Three near-identical past flash campaigns with +50% / +60% / +70% lifts.
    // Each on its own SKU so sales windows don't interfere.
    // Median = 60. P25 = (50+60)/2 = 55. P75 = (60+70)/2 = 65.
    $brief = [
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
        'discount_type'  => 'percent_off',
        'channel_mix'    => ['paid_social', 'email'],
        'ad_spend_band'  => 'high',
        'audience'       => 'both',
    ];

    [, $sku1] = makePastPromoOnNewSku($brief, 120, 115);
    seedSalesAroundPromo($sku1, 120, 115, 4.0, 6.0); // +50%

    [, $sku2] = makePastPromoOnNewSku($brief, 100, 95);
    seedSalesAroundPromo($sku2, 100, 95, 5.0, 8.0);  // +60%

    [, $sku3] = makePastPromoOnNewSku($brief, 80, 75);
    seedSalesAroundPromo($sku3, 80, 75, 4.0, 6.8);   // +70%

    $result = (new NearestNeighborLayer(new BriefVectorizer))->predict($brief);

    expect($result['sample_size'])->toBe(3)
        ->and($result['value'])->toBe(60.0)
        ->and($result['lower'])->toBe(55.0)
        ->and($result['upper'])->toBe(65.0)
        ->and($result['basis'])->toContain('3 similar past campaign(s)');
});

it('skips past promotions whose baseline is zero (intermittent demand)', function () {
    // Two past promos, separate SKUs:
    //   - one with computable lift (+50%)
    //   - one with no baseline sales seeded → uncomputable, must skip
    $brief = ['promotion_type' => 'flash', 'discount_pct' => 30];

    [, $skuLift] = makePastPromoOnNewSku($brief, 100, 95);
    seedSalesAroundPromo($skuLift, 100, 95, 4.0, 6.0); // +50%

    makePastPromoOnNewSku($brief, 80, 75);
    // No sales seeded for this SKU → baseline = 0 → uncomputable

    $result = (new NearestNeighborLayer(new BriefVectorizer))->predict($brief);

    expect($result['sample_size'])->toBe(1)
        ->and($result['value'])->toBe(50.0);
});

it('clamps a negative median to zero', function () {
    // Single past promo where sales DROPPED during the window — actual
    // lift is negative. Layer output clamps the value/lower/upper to 0.
    [, $sku] = makePastPromoOnNewSku([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
    ], 100, 95);
    seedSalesAroundPromo($sku, 100, 95, 10.0, 5.0); // -50%

    $result = (new NearestNeighborLayer(new BriefVectorizer))->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
    ]);

    expect($result['sample_size'])->toBe(1)
        ->and($result['value'])->toBe(0.0)
        ->and($result['lower'])->toBe(0.0)
        ->and($result['upper'])->toBe(0.0);
});

it('limits the candidate pool to the top-K nearest neighbors by Brief similarity', function () {
    // Seed 12 identical past campaigns (one Brief shape, all +100% lift,
    // one SKU each). Top-K caps at 10 — the layer should pull 10 of them,
    // not all 12. Median across any 10 of the 12 identical lifts is 100.
    $brief = [
        'promotion_type' => 'clearance',
        'discount_pct'   => 60,
        'discount_type'  => 'percent_off',
        'channel_mix'    => ['email'],
        'ad_spend_band'  => 'low',
        'audience'       => 'existing_customers',
    ];

    for ($i = 0; $i < 12; $i++) {
        $offset = 100 + ($i * 50); // wide spacing — sales windows don't overlap
        [, $sku] = makePastPromoOnNewSku($brief, $offset, $offset - 5);
        seedSalesAroundPromo($sku, $offset, $offset - 5, 5.0, 10.0); // +100%
    }

    $result = (new NearestNeighborLayer(new BriefVectorizer))->predict($brief);

    expect($result['sample_size'])->toBe(10)
        ->and($result['value'])->toBe(100.0);
});

it('reports the layer name in the output for orchestrator routing', function () {
    $result = (new NearestNeighborLayer(new BriefVectorizer))->predict(['promotion_type' => 'flash']);

    expect($result)->toHaveKey('layer')
        ->and($result['layer'])->toBe('nearest_neighbor');
});
