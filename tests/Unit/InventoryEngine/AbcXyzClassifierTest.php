<?php

use App\Models\Sku;
use App\Models\Supplier;
use App\Models\SalesHistory;
use App\Services\InventoryEngine\AbcXyzClassifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('classifies high-revenue sku as A', function () {
    $supplier = Supplier::factory()->create();

    // Expensive SKU with high sales → A class
    $skuA = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 50000]); // 500 SAR
    // Cheap SKU with low sales → C class
    $skuC = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 800]);  // 8 SAR

    $since = Carbon::today()->subDays(89);
    foreach (range(0, 89) as $i) {
        SalesHistory::create(['sku_id' => $skuA->id, 'sale_date' => $since->copy()->addDays($i), 'quantity_sold' => 5]);
        SalesHistory::create(['sku_id' => $skuC->id, 'sale_date' => $since->copy()->addDays($i), 'quantity_sold' => 1]);
    }

    $skus = Sku::whereIn('id', [$skuA->id, $skuC->id])->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($skuA->id)->abc_class)->toBe('A');
    expect(Sku::find($skuC->id)->abc_class)->toBe('C');
});

it('classifies stable demand sku as X', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 1000]);

    $since = Carbon::today()->subDays(89);
    // Perfectly stable demand → CV = 0 → X
    foreach (range(0, 89) as $i) {
        SalesHistory::create(['sku_id' => $sku->id, 'sale_date' => $since->copy()->addDays($i), 'quantity_sold' => 5]);
    }

    $skus = Sku::where('id', $sku->id)->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($sku->id)->xyz_class)->toBe('X');
});

it('classifies erratic demand sku as Z', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id, 'unit_cost' => 1000]);

    $since = Carbon::today()->subDays(89);
    // Highly variable demand → high CV → Z
    foreach (range(0, 89) as $i) {
        SalesHistory::create([
            'sku_id' => $sku->id,
            'sale_date' => $since->copy()->addDays($i),
            'quantity_sold' => $i % 10 === 0 ? 50 : 0, // spikes every 10 days
        ]);
    }

    $skus = Sku::where('id', $sku->id)->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($sku->id)->xyz_class)->toBe('Z');
});

it('classifies sku with no sales as Z', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $skus = Sku::where('id', $sku->id)->get();
    (new AbcXyzClassifier())->classify($skus);

    expect(Sku::find($sku->id)->xyz_class)->toBe('Z');
});

it('returns correct safety stock multiplier per class combination', function () {
    $classifier = new AbcXyzClassifier();

    expect($classifier->getSafetyStockMultiplier('A', 'Z'))->toBe(1.5);
    expect($classifier->getSafetyStockMultiplier('A', 'Y'))->toBe(1.2);
    expect($classifier->getSafetyStockMultiplier('C', 'Z'))->toBe(0.8);
    expect($classifier->getSafetyStockMultiplier('A', 'X'))->toBe(1.0);
    expect($classifier->getSafetyStockMultiplier('B', 'Y'))->toBe(1.0);
});
