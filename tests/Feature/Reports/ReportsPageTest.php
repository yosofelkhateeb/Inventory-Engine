<?php

use App\Models\ForecastModelRegistry;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;

it('renders the reports page with registry rows', function () {
    $user     = User::factory()->create(['tenant_id' => 1]);
    $supplier = Supplier::factory()->create();
    $sku      = Sku::factory()->create(['supplier_id' => $supplier->id, 'tenant_id' => 1]);

    ForecastModelRegistry::withoutGlobalScopes()->create([
        'tenant_id'           => 1,
        'sku_id'              => $sku->id,
        'model_name'          => 'holt_winters',
        'demand_rate'         => 3.5,
        'mae'                 => 0.8,
        'smape'               => 14.2,
        'selection_rationale' => 'holt_winters beat baseline',
        'trained_at'          => now(),
        'next_review_at'      => now()->addDays(30),
    ]);

    $this->actingAs($user)
         ->get('/reports')
         ->assertOk()
         ->assertInertia(fn ($page) => $page
             ->component('Reports/Index')
             ->has('rows', 1)
             ->where('rows.0.model_name', 'holt_winters')
             ->where('rows.0.mae', 0.8)
         );
});

it('redirects guests to login', function () {
    $this->get('/reports')->assertRedirect('/login');
});
