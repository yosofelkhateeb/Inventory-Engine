<?php

use App\Models\InventoryDecision;
use App\Models\Sku;

it('stores a decision with reasoning json', function () {
    $sku = Sku::factory()->create();

    $decision = InventoryDecision::create([
        'sku_id' => $sku->id,
        'run_at' => now(),
        'decision' => 'order',
        'recommended_qty' => 48,
        'constrained_qty' => 48,
        'reasoning' => ['reorder_point' => 22, 'effective_position' => 15],
        'forecast_demand' => 3.2,
        'days_of_cover' => 4.7,
        'reorder_point' => 22,
    ]);

    expect($decision->decision)->toBe('order')
        ->and($decision->reasoning)->toBeArray()
        ->and($decision->reasoning['reorder_point'])->toBe(22);
});
