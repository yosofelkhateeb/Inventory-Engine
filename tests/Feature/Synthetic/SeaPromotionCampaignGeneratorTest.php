<?php

use App\Models\Promotion;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\Synthetic\SeaPromotionCampaignGenerator;
use App\Support\TenantContext;
use Carbon\Carbon;

beforeEach(function () {
    TenantContext::set(1);
});

afterEach(function () {
    TenantContext::clear();
});

/** Seed a minimal 15-SKU SEA-shaped catalog for the generator to target. */
function seedMinimalSeaCatalog(): array
{
    $supplier = Supplier::factory()->create(['tenant_id' => 1]);
    $skus     = [];
    foreach (['equipment', 'accessory', 'bundle'] as $cat) {
        for ($i = 0; $i < 5; $i++) {
            $skus[] = Sku::factory()->create([
                'tenant_id'   => 1,
                'supplier_id' => $supplier->id,
                'category'    => $cat,
            ]);
        }
    }
    return $skus;
}

it('creates 50-70 Brief-tagged campaigns over the 30-month window', function () {
    seedMinimalSeaCatalog();

    $result = (new SeaPromotionCampaignGenerator(seed: 42))->generate();

    expect($result['promos_created'])->toBeGreaterThanOrEqual(50)
        ->and($result['promos_created'])->toBeLessThanOrEqual(70)
        ->and(Promotion::count())->toBe($result['promos_created']);
});

it('populates all Campaign Brief fields on every promo', function () {
    seedMinimalSeaCatalog();

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();

    foreach (Promotion::all() as $promo) {
        expect($promo->discount_pct)->toBeGreaterThan(0)
            ->and($promo->discount_type)->toBeIn(Promotion::DISCOUNT_TYPES)
            ->and($promo->channel_mix)->toBeArray()->not->toBeEmpty()
            ->and($promo->ad_spend_band)->toBeIn(Promotion::AD_SPEND_BANDS)
            ->and($promo->audience)->toBeIn(Promotion::AUDIENCES)
            ->and($promo->lead_announcement_days)->toBeGreaterThanOrEqual(0)
            ->and($promo->promotion_type)->toBeIn(['flash', 'clearance', 'seasonal', 'bundle', 'other'])
            ->and($promo->expected_uplift_pct)->toBeGreaterThan(0);
    }
});

it('generates diverse promotion types', function () {
    seedMinimalSeaCatalog();

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();

    $types = Promotion::pluck('promotion_type')->unique()->sort()->values()->all();
    // Expect at least flash, seasonal, clearance — the anchored events + quarterly schedule guarantee these.
    expect($types)->toContain('flash')
        ->and($types)->toContain('seasonal')
        ->and($types)->toContain('clearance');
});

it('uses all three targeting modes (all / category / specific)', function () {
    seedMinimalSeaCatalog();

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();

    $all = Promotion::where('affects_all_skus', true)->count();
    $cat = Promotion::where('affects_all_skus', false)
        ->whereNotNull('applies_to_categories')
        ->count();
    $spec = Promotion::where('affects_all_skus', false)
        ->whereNull('applies_to_categories')
        ->count();

    expect($all)->toBeGreaterThan(0, 'expected some affects_all_skus campaigns')
        ->and($cat)->toBeGreaterThan(0, 'expected some category-targeted campaigns')
        ->and($spec)->toBeGreaterThan(0, 'expected some specific-SKU campaigns');
});

it('boosts SalesHistory rows inside the 11.11 anchored window and tags them as promotion days', function () {
    $skus    = seedMinimalSeaCatalog();
    $equipment = collect($skus)->firstWhere('category', 'equipment');

    // Seed 3 rows inside the 11.11 2024 campaign window (Nov 10-12). The
    // generator's other campaigns may also pick this SKU for specific
    // targeting on other dates — that's realistic behavior — so the test
    // only asserts the 11.11 window, which is deterministically all_skus.
    foreach (['2024-11-10', '2024-11-11', '2024-11-12'] as $date) {
        SalesHistory::create([
            'tenant_id'     => 1,
            'sku_id'        => $equipment->id,
            'sale_date'     => $date,
            'quantity_sold' => 10,
        ]);
    }

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();

    // whereDate avoids a SQLite quirk where the date column stores a
    // datetime suffix and whereBetween's text comparison excludes the
    // upper bound ('2024-11-12 00:00:00' > '2024-11-12').
    $inWindow = SalesHistory::where('sku_id', $equipment->id)
        ->whereDate('sale_date', '>=', '2024-11-10')
        ->whereDate('sale_date', '<=', '2024-11-12')
        ->get();

    expect($inWindow)->toHaveCount(3);
    foreach ($inWindow as $row) {
        expect($row->is_promotion)->toBeTrue()
            ->and((int) $row->quantity_sold)->toBeGreaterThan(10);
    }
});

it('is deterministic — same seed produces identical promo counts and types', function () {
    seedMinimalSeaCatalog();

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();
    $firstCount = Promotion::count();
    $firstTypes = Promotion::orderBy('start_date')->orderBy('id')->pluck('promotion_type')->all();

    Promotion::query()->forceDelete();

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();
    $secondCount = Promotion::count();
    $secondTypes = Promotion::orderBy('start_date')->orderBy('id')->pluck('promotion_type')->all();

    expect($secondCount)->toBe($firstCount)
        ->and($secondTypes)->toBe($firstTypes);
});

it('enforces ≥31-day gap between consecutive affects_all_skus campaigns', function () {
    seedMinimalSeaCatalog();

    (new SeaPromotionCampaignGenerator(seed: 42))->generate();

    $allSkuPromos = Promotion::where('affects_all_skus', true)
        ->orderBy('start_date')
        ->get();

    expect($allSkuPromos->count())->toBeGreaterThan(1);

    for ($i = 1; $i < $allSkuPromos->count(); $i++) {
        $prevEnd     = Carbon::parse($allSkuPromos[$i - 1]->end_date);
        $currentStart = Carbon::parse($allSkuPromos[$i]->start_date);
        $gap         = $prevEnd->diffInDays($currentStart);
        expect($gap)->toBeGreaterThanOrEqual(
            31,
            "Gap between all-SKU promo {$i} (start {$currentStart->toDateString()}) and prior (end {$prevEnd->toDateString()}) is only {$gap} days"
        );
    }
});
