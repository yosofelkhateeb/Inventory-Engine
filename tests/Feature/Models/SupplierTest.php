<?php

use App\Models\Supplier;

it('can create a supplier', function () {
    $supplier = Supplier::factory()->create([
        'name' => 'Gulf Trading Co',
        'avg_lead_time_days' => 7,
        'lead_time_stddev' => 1.5,
    ]);

    expect($supplier->name)->toBe('Gulf Trading Co')
        ->and($supplier->avg_lead_time_days)->toBe(7)
        ->and($supplier->lead_time_stddev)->toBe(1.5);
});
