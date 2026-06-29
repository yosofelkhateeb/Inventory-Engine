<?php

use App\Models\Sku;
use App\Models\Supplier;

it('can create a sku with supplier relationship', function () {
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    expect($sku->supplier->id)->toBe($supplier->id)
        ->and($sku->current_stock)->toBeInt()
        ->and($sku->moq)->toBeInt();
});

it('calculates effective position', function () {
    $sku = Sku::factory()->create([
        'current_stock' => 100,
        'in_transit_qty' => 20,
        'reserved_qty' => 10,
    ]);

    expect($sku->effective_position)->toBe(110);
});

it('converts unit_cost to SAR', function () {
    $sku = Sku::factory()->create(['unit_cost' => 1550]);
    expect($sku->unit_cost_sar)->toBe(15.5);
});
