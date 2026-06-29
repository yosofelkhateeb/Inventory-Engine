<?php

use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;

it('lists all skus for authenticated user', function () {
    $user = User::factory()->create();
    Sku::factory(3)->create();

    $this->actingAs($user)
         ->get('/skus')
         ->assertStatus(200)
         ->assertInertia(fn ($page) => $page
             ->component('SKUs/Index')
             ->has('skus', 3)
         );
});

it('shows a single sku with its latest decision', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $this->actingAs($user)
         ->get("/skus/{$sku->id}")
         ->assertStatus(200)
         ->assertInertia(fn ($page) => $page->component('SKUs/Show'));
});

it('returns json summary for an authenticated user', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $this->actingAs($user)
         ->getJson("/skus/{$sku->id}/summary")
         ->assertStatus(200)
         ->assertJsonStructure([
             'id', 'sku_code', 'name', 'supplier_name',
             'unit_cost_sar', 'moq', 'lead_time_days',
             'abc_class', 'xyz_class',
             'current_stock', 'in_transit_qty', 'reserved_qty', 'effective_position',
             'decision', 'constrained_qty', 'days_of_cover',
             'reorder_point', 'forecast_demand', 'safety_stock', 'run_at',
         ])
         ->assertJson(['unit_cost_sar' => $sku->unit_cost_sar]);
});

it('summary returns null decision fields when no decision exists', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $response = $this->actingAs($user)
                     ->getJson("/skus/{$sku->id}/summary")
                     ->assertStatus(200)
                     ->json();

    expect($response['decision'])->toBeNull()
        ->and($response['days_of_cover'])->toBeNull()
        ->and($response['safety_stock'])->toBeNull();
});

it('summary endpoint redirects guests to login', function () {
    $sku = Sku::factory()->create();

    $this->get("/skus/{$sku->id}/summary")
         ->assertRedirect('/login');
});

it('updates editable fields and converts unit_cost_sar to halalas', function () {
    $user        = User::factory()->create();
    $supplierA   = Supplier::factory()->create();
    $supplierB   = Supplier::factory()->create();
    $sku         = Sku::factory()->create([
        'supplier_id'    => $supplierA->id,
        'name'           => 'Old name',
        'category'       => 'accessory',
        'lead_time_days' => 7,
        'moq'            => 10,
        'unit_cost'      => 1000, // 10.00 SAR
    ]);

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", [
            'name'           => 'New name',
            'category'       => 'equipment',
            'supplier_id'    => $supplierB->id,
            'lead_time_days' => 14,
            'moq'            => 20,
            'unit_cost_sar'  => 12.50,
        ])
        ->assertRedirect();

    $sku->refresh();
    expect($sku->name)->toBe('New name')
        ->and($sku->category)->toBe('equipment')
        ->and($sku->supplier_id)->toBe($supplierB->id)
        ->and($sku->lead_time_days)->toBe(14)
        ->and($sku->moq)->toBe(20)
        ->and($sku->unit_cost)->toBe(1250); // 12.50 SAR -> 1250 halalas
});

it('persists safety_stock_multiplier_override when supplied and clears it on null', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create(['safety_stock_multiplier_override' => null]);

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", ['safety_stock_multiplier_override' => 1.30])
        ->assertRedirect();
    $sku->refresh();
    expect((float) $sku->safety_stock_multiplier_override)->toBe(1.30);

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", ['safety_stock_multiplier_override' => null])
        ->assertRedirect();
    $sku->refresh();
    expect($sku->safety_stock_multiplier_override)->toBeNull();
});

it('rejects invalid update inputs', function () {
    $user = User::factory()->create();
    $sku  = Sku::factory()->create();

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", ['category' => 'unknown'])
        ->assertSessionHasErrors('category');

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", ['lead_time_days' => -1])
        ->assertSessionHasErrors('lead_time_days');

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", ['moq' => 0])
        ->assertSessionHasErrors('moq');

    $this->actingAs($user)
        ->patch("/skus/{$sku->id}", ['safety_stock_multiplier_override' => 99])
        ->assertSessionHasErrors('safety_stock_multiplier_override');
});

it('update endpoint redirects guests to login', function () {
    $sku = Sku::factory()->create();

    $this->patch("/skus/{$sku->id}", ['name' => 'Anything'])
        ->assertRedirect('/login');
});
