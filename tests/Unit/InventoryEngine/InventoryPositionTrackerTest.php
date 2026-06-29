<?php

use App\Models\Sku;
use App\Services\InventoryEngine\DTOs\InventoryPosition;
use App\Services\InventoryEngine\InventoryPositionTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('calculates effective position correctly', function () {
    $sku = Sku::factory()->create([
        'current_stock'  => 100,
        'in_transit_qty' => 20,
        'reserved_qty'   => 10,
    ]);
    $tracker  = new InventoryPositionTracker();
    $position = $tracker->getPosition($sku->id, 3.2);

    expect($position)->toBeInstanceOf(InventoryPosition::class)
        ->and($position->effective_position)->toBe(110)
        ->and($position->days_of_cover)->toBe(34.38);
});

it('returns zero days of cover when demand is zero', function () {
    $sku     = Sku::factory()->create(['current_stock' => 50]);
    $tracker = new InventoryPositionTracker();
    $position = $tracker->getPosition($sku->id, 0.0);
    expect($position->days_of_cover)->toBe(0.0);
});
