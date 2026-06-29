<?php

use App\Models\Sku;
use App\Services\InventoryEngine\ConstraintEngine;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rounds up raw qty to nearest MOQ', function () {
    $sku = Sku::factory()->create(['moq' => 24, 'unit_cost' => 4500]);
    $result = (new ConstraintEngine())->applyConstraints($sku->id, 30, 99999900);
    // 30 → rounds up to 48 (next multiple of 24)
    expect($result->final_qty)->toBe(48)
        ->and($result->budget_blocked)->toBeFalse();
});

it('flags budget blocked when cost exceeds remaining budget', function () {
    $sku = Sku::factory()->create(['moq' => 6, 'unit_cost' => 38000]);
    // Budget: 100000 halalas = 1000 SAR. MOQ cost: 6 × 380 SAR = 2280 SAR > 1000 → blocked
    $result = (new ConstraintEngine())->applyConstraints($sku->id, 6, 100000);
    expect($result->budget_blocked)->toBeTrue()
        ->and($result->final_qty)->toBe(0);
});

it('never goes below MOQ when budget allows', function () {
    $sku = Sku::factory()->create(['moq' => 12, 'unit_cost' => 1000]);
    $result = (new ConstraintEngine())->applyConstraints($sku->id, 5, 99999900);
    expect($result->final_qty)->toBe(12);
});
