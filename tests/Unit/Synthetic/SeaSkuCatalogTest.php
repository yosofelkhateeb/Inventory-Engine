<?php

use Tests\Fixtures\ShopifyOrderFactory;

/**
 * Structural tests for the SEA SKU catalog. The catalog is a data file,
 * not code — these tests catch typos and drift in counts/distribution
 * before they reach the orchestrator (Step 4).
 */

function loadSeaCatalog(): array
{
    // __DIR__ keeps this test runnable without bootstrapping Laravel —
    // base_path() would require the Feature dir's TestCase setup, which is
    // overkill for a pure data-structure check.
    return require __DIR__ . '/../../../database/seeders/data/sea_sku_catalog.php';
}

it('catalog exposes exactly 5 suppliers and 30 SKUs', function () {
    $catalog = loadSeaCatalog();

    expect($catalog)->toHaveKey('suppliers')
        ->and($catalog)->toHaveKey('skus')
        ->and($catalog['suppliers'])->toHaveCount(5)
        ->and($catalog['skus'])->toHaveCount(30);
});

it('every supplier has name, avg lead time, and stddev', function () {
    foreach (loadSeaCatalog()['suppliers'] as $key => $sup) {
        expect($sup)->toHaveKey('name')
            ->and($sup)->toHaveKey('avg_lead_time_days')
            ->and($sup)->toHaveKey('lead_time_stddev')
            ->and($sup['name'])->toBeString()->not->toBeEmpty()
            ->and($sup['avg_lead_time_days'])->toBeInt()->toBeGreaterThan(0)
            ->and($sup['lead_time_stddev'])->toBeFloat()->toBeGreaterThan(0.0);
    }
});

it('supplier lead times span fast-local through international', function () {
    $leads = collect(loadSeaCatalog()['suppliers'])->pluck('avg_lead_time_days')->sort()->values();

    // Spec: 5, 7, 14, 18, 28. Allow flexibility but assert range coverage.
    expect($leads->min())->toBeLessThanOrEqual(7, 'expected at least one fast-local supplier (≤7 day lead)')
        ->and($leads->max())->toBeGreaterThanOrEqual(21, 'expected at least one international slow supplier (≥21 day lead)')
        ->and($leads->count())->toBe(5);
});

it('every SKU declares the required fields with valid types', function () {
    foreach (loadSeaCatalog()['skus'] as $sku) {
        expect($sku)->toHaveKey('sku_code')
            ->and($sku)->toHaveKey('name')
            ->and($sku)->toHaveKey('category')
            ->and($sku)->toHaveKey('pathology')
            ->and($sku)->toHaveKey('base_level')
            ->and($sku)->toHaveKey('supplier_key')
            ->and($sku)->toHaveKey('unit_cost')
            ->and($sku)->toHaveKey('moq')
            ->and($sku)->toHaveKey('reorder_qty')
            ->and($sku['category'])->toBeIn(['equipment', 'accessory', 'bundle'])
            ->and($sku['pathology'])->toBeIn(ShopifyOrderFactory::PATHOLOGIES)
            ->and($sku['base_level'])->toBeInt()->toBeGreaterThan(0)
            ->and($sku['unit_cost'])->toBeInt()->toBeGreaterThan(0)
            ->and($sku['moq'])->toBeInt()->toBeGreaterThan(0)
            ->and($sku['reorder_qty'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('SKU codes are unique', function () {
    $codes = collect(loadSeaCatalog()['skus'])->pluck('sku_code');
    expect($codes->count())->toBe($codes->unique()->count(), 'duplicate SKU codes in catalog');
});

it('every SKU references a supplier_key that exists', function () {
    $catalog      = loadSeaCatalog();
    $supplierKeys = array_keys($catalog['suppliers']);

    foreach ($catalog['skus'] as $sku) {
        expect($sku['supplier_key'])->toBeIn($supplierKeys);
    }
});

it('category distribution matches the design — 6 equipment, 18 accessories, 6 bundles', function () {
    $byCategory = collect(loadSeaCatalog()['skus'])->countBy('category');

    expect($byCategory['equipment'] ?? 0)->toBe(6)
        ->and($byCategory['accessory'] ?? 0)->toBe(18)
        ->and($byCategory['bundle'] ?? 0)->toBe(6);
});

it('equipment pathology distribution matches the design — 4 clean + 2 stopped_selling', function () {
    $equipmentByPathology = collect(loadSeaCatalog()['skus'])
        ->where('category', 'equipment')
        ->countBy('pathology');

    expect($equipmentByPathology['clean'] ?? 0)->toBe(4)
        ->and($equipmentByPathology['stopped_selling'] ?? 0)->toBe(2);
});

it('accessory pathology distribution matches the design — 9 clean + 6 sparse + 3 promo_spike', function () {
    $accessoryByPathology = collect(loadSeaCatalog()['skus'])
        ->where('category', 'accessory')
        ->countBy('pathology');

    expect($accessoryByPathology['clean'] ?? 0)->toBe(9)
        ->and($accessoryByPathology['sparse'] ?? 0)->toBe(6)
        ->and($accessoryByPathology['promo_spike'] ?? 0)->toBe(3);
});

it('all bundles are promo_spike pathology', function () {
    $bundleByPathology = collect(loadSeaCatalog()['skus'])
        ->where('category', 'bundle')
        ->countBy('pathology');

    expect($bundleByPathology['promo_spike'] ?? 0)->toBe(6);
});

it('equipment base_levels are in the high-volume range (10-25)', function () {
    $equipmentBaseLevels = collect(loadSeaCatalog()['skus'])
        ->where('category', 'equipment')
        ->pluck('base_level');

    foreach ($equipmentBaseLevels as $level) {
        expect($level)->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(25);
    }
});
