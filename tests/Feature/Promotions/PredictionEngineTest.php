<?php

use App\Models\Promotion;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Services\Promotions\BriefVectorizer;
use App\Services\Promotions\Layers\MlLayer;
use App\Services\Promotions\Layers\NearestNeighborLayer;
use App\Services\Promotions\Layers\RuleBasedLayer;
use App\Services\Promotions\PredictionEngine;
use Illuminate\Support\Facades\Process;
use App\Support\TenantContext;
use Carbon\Carbon;

function seedSetting(string $key, int|float|string $value): void
{
    SystemSetting::create([
        'tenant_id' => 1,
        'key'       => $key,
        'value'     => (string) $value,
        'group'     => 'forecasting',
    ]);
}

/**
 * Make a past Brief-tagged promotion on its own SKU, with seeded sales
 * yielding the requested actual lift. Returns the Promotion.
 */
function makeBriefTaggedPromo(array $brief, int $startOffset, int $endOffset, float $baseline, float $during): Promotion
{
    $supplier = Supplier::factory()->create(['tenant_id' => 1]);
    $sku      = Sku::factory()->create(['tenant_id' => 1, 'supplier_id' => $supplier->id]);

    $promo = Promotion::create(array_merge([
        'tenant_id'           => 1,
        'name'                => 'Past brief ' . uniqid(),
        'start_date'          => Carbon::today()->subDays($startOffset),
        'end_date'            => Carbon::today()->subDays($endOffset),
        'expected_uplift_pct' => 30,
        'affects_all_skus'    => false,
    ], $brief));
    $promo->skus()->attach($sku->id);

    for ($i = $startOffset + 1; $i <= $startOffset + 30; $i++) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => Carbon::today()->subDays($i)->toDateString(),
            'quantity_sold' => $baseline,
        ]);
    }
    for ($i = $endOffset; $i <= $startOffset; $i++) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $sku->id,
            'sale_date'     => Carbon::today()->subDays($i)->toDateString(),
            'quantity_sold' => $during,
        ]);
    }

    return $promo;
}

function makeEngine(): PredictionEngine
{
    $vectorizer = new BriefVectorizer;
    return new PredictionEngine(
        new RuleBasedLayer,
        new NearestNeighborLayer($vectorizer),
        new MlLayer($vectorizer),
    );
}

beforeEach(function () {
    TenantContext::set(1);
});

afterEach(function () {
    TenantContext::clear();
});

it('routes to Layer 1 (rules) when tagged_briefs is below min_nn_samples', function () {
    seedSetting('uplift_default.flash', 50);
    seedSetting('uplift.min_nn_samples', 5);

    // No past Brief-tagged promos seeded — cold start.
    $result = makeEngine()->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
    ]);

    expect($result['layer'])->toBe('rules')
        ->and($result['sample_size'])->toBe(0);
});

it('routes to Layer 2 (NN) when enough tagged briefs exist and the layer finds usable neighbors', function () {
    seedSetting('uplift.min_nn_samples', 5);
    seedSetting('uplift.rule.discount_pct_coef', 0.5);

    $brief = [
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
        'discount_type'  => 'percent_off',
    ];

    // Five past Brief-tagged promos with computable lift, well-spaced.
    for ($i = 0; $i < 5; $i++) {
        $offset = 100 + ($i * 50);
        makeBriefTaggedPromo($brief, $offset, $offset - 5, 5.0, 7.5); // +50%
    }

    $result = makeEngine()->predict($brief);

    expect($result['layer'])->toBe('nearest_neighbor')
        ->and($result['sample_size'])->toBe(5)
        ->and($result['value'])->toBe(50.0);
});

it('falls back to Layer 1 when tagged_briefs is high but Layer 2 sample_size is below the floor', function () {
    seedSetting('uplift_default.flash', 50);
    seedSetting('uplift.min_nn_samples', 5);

    // Five past Brief-tagged promos exist (passes the gate)…
    // …but each has zero baseline sales, so Layer 2 returns sample_size=0.
    // Engine should fall back to Layer 1 instead of returning a useless 0%.
    for ($i = 0; $i < 5; $i++) {
        $offset = 100 + ($i * 50);
        $supplier = Supplier::factory()->create(['tenant_id' => 1]);
        $sku      = Sku::factory()->create(['tenant_id' => 1, 'supplier_id' => $supplier->id]);
        $promo = Promotion::create([
            'tenant_id'           => 1,
            'name'                => "P{$i}",
            'promotion_type'      => 'flash',
            'discount_pct'        => 30,
            'start_date'          => Carbon::today()->subDays($offset),
            'end_date'            => Carbon::today()->subDays($offset - 5),
            'expected_uplift_pct' => 30,
            'affects_all_skus'    => false,
        ]);
        $promo->skus()->attach($sku->id);
        // No sales seeded → baseline = 0 → uncomputable.
    }

    $result = makeEngine()->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
    ]);

    // Layer 1 routing is the contract being tested here; the exact rule
    // arithmetic is covered in RuleBasedLayerTest. Just assert routing
    // and that Layer 2's empty-result didn't leak through (sample_size 0
    // would mean Layer 2 served — we want Layer 1 instead).
    expect($result['layer'])->toBe('rules');
});

it('only counts Brief-tagged promotions toward the activation threshold', function () {
    seedSetting('uplift_default.flash', 50);
    seedSetting('uplift.min_nn_samples', 5);

    // Five past promotions exist, but NONE have discount_pct set
    // (legacy v1 rows from before the Brief migration). Should NOT
    // pass the activation gate — engine routes to Layer 1.
    for ($i = 0; $i < 5; $i++) {
        $offset = 100 + ($i * 50);
        Promotion::create([
            'tenant_id'           => 1,
            'name'                => "Legacy {$i}",
            'promotion_type'      => 'flash',
            'discount_pct'        => null, // ← legacy row, no Brief
            'start_date'          => Carbon::today()->subDays($offset),
            'end_date'            => Carbon::today()->subDays($offset - 5),
            'expected_uplift_pct' => 30,
            'affects_all_skus'    => true,
        ]);
    }

    $result = makeEngine()->predict([
        'promotion_type' => 'flash',
        'discount_pct'   => 30,
    ]);

    expect($result['layer'])->toBe('rules');
});

it('respects a custom min_nn_samples threshold from settings', function () {
    seedSetting('uplift_default.flash', 50);
    seedSetting('uplift.min_nn_samples', 3); // tighter than default

    // Three past Brief-tagged promos with computable lift.
    $brief = ['promotion_type' => 'flash', 'discount_pct' => 30];
    for ($i = 0; $i < 3; $i++) {
        $offset = 100 + ($i * 50);
        makeBriefTaggedPromo($brief, $offset, $offset - 5, 5.0, 6.0); // +20%
    }

    $result = makeEngine()->predict($brief);

    // 3 ≥ min_nn_samples=3, so Layer 2 should fire.
    expect($result['layer'])->toBe('nearest_neighbor')
        ->and($result['sample_size'])->toBe(3);
});

it('routes to Layer 3 (ML) when tagged_briefs crosses min_ml_samples and Python returns a usable result', function () {
    seedSetting('uplift.min_nn_samples', 5);
    seedSetting('uplift.min_ml_samples', 50);

    // 50 tagged Brief promos, all with computable lift. Each on its own
    // SKU so seeded sales windows don't overlap.
    $brief = ['promotion_type' => 'flash', 'discount_pct' => 30];
    for ($i = 0; $i < 50; $i++) {
        $offset = 100 + ($i * 30);
        makeBriefTaggedPromo($brief, $offset, $offset - 5, 5.0, 7.5);
    }

    // Fake the Python subprocess — return a clean ML-shaped JSON payload.
    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'value'       => 47.3,
                'lower'       => 28.0,
                'upper'       => 62.5,
                'basis'       => 'ML model trained on 50 past campaigns',
                'sample_size' => 50,
                'layer'       => 'ml',
            ]),
            errorOutput: '',
            exitCode: 0,
        ),
    ]);

    $result = makeEngine()->predict($brief);

    expect($result['layer'])->toBe('ml')
        ->and($result['sample_size'])->toBe(50)
        ->and($result['value'])->toBe(47.3)
        ->and($result['lower'])->toBe(28.0)
        ->and($result['upper'])->toBe(62.5);
});

it('falls back to Layer 2 when Python subprocess abstains', function () {
    seedSetting('uplift.min_nn_samples', 5);
    seedSetting('uplift.min_ml_samples', 50);

    // Threshold met for ML, AND ≥ min_nn_samples worth of usable history
    // so Layer 2 has something to fall back on.
    $brief = ['promotion_type' => 'flash', 'discount_pct' => 30];
    for ($i = 0; $i < 50; $i++) {
        $offset = 100 + ($i * 30);
        makeBriefTaggedPromo($brief, $offset, $offset - 5, 5.0, 7.5);
    }

    // Fake the subprocess returning the abstain shape (sample_size=0)
    // — what Python returns when LightGBM is missing or training fails.
    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'value'       => 0.0,
                'lower'       => 0.0,
                'upper'       => 0.0,
                'basis'       => 'LightGBM unavailable: No module named lightgbm',
                'sample_size' => 0,
                'layer'       => 'ml',
            ]),
            errorOutput: '',
            exitCode: 0,
        ),
    ]);

    $result = makeEngine()->predict($brief);

    expect($result['layer'])->toBe('nearest_neighbor')
        ->and($result['sample_size'])->toBeGreaterThan(0);
});

it('falls back to Layer 2 when Python subprocess exits non-zero', function () {
    seedSetting('uplift.min_nn_samples', 5);
    seedSetting('uplift.min_ml_samples', 50);

    $brief = ['promotion_type' => 'flash', 'discount_pct' => 30];
    for ($i = 0; $i < 50; $i++) {
        $offset = 100 + ($i * 30);
        makeBriefTaggedPromo($brief, $offset, $offset - 5, 5.0, 7.5);
    }

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $result = makeEngine()->predict($brief);

    expect($result['layer'])->toBe('nearest_neighbor');
});
