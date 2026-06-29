<?php

use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use App\Models\SalesHistory;

it('shows dashboard to authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200)
             ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
});

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('sends enriched decision data with stock fields', function () {
    $user     = User::factory()->create();
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);
    $sku      = Sku::factory()->create([
        'supplier_id'    => $supplier->id,
        'current_stock'  => 50,
        'in_transit_qty' => 10,
        'reserved_qty'   => 5,
        'lead_time_days' => 7,
    ]);

    InventoryDecision::create([
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'order',
        'recommended_qty' => 24,
        'constrained_qty' => 24,
        'reasoning'       => ['safety_stock' => 4.37, 'reorder_point' => 25.37],
        'forecast_demand' => 3.2,
        'days_of_cover'   => 11.0,
        'reorder_point'   => 25.37,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('stats.order_now')
            ->has('stats.order_soon')
            ->has('stats.watchlist')
            ->has('stats.avg_days_cover')
            ->missing('stats.committed_spend')
            ->has('decisions.0.current_stock')
            ->has('decisions.0.in_transit_qty')
            ->has('decisions.0.reserved_qty')
            ->has('decisions.0.effective_position')
            ->has('decisions.0.lead_time_days')
            ->has('decisions.0.forecast_demand')
            ->has('decisions.0.safety_stock')
        );
});

it('sorts decisions by urgency with critical order-now first', function () {
    $user     = User::factory()->create();
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);
    $runAt    = now();

    // HOLD sku — should appear last
    $skuHold = Sku::factory()->create(['supplier_id' => $supplier->id, 'lead_time_days' => 7]);
    InventoryDecision::create([
        'sku_id' => $skuHold->id, 'run_at' => $runAt, 'decision' => 'hold',
        'recommended_qty' => 0, 'constrained_qty' => 0,
        'reasoning' => [], 'forecast_demand' => 1.0, 'days_of_cover' => 30.0, 'reorder_point' => 10.0,
    ]);

    // ORDER NOW critical (days_of_cover < lead_time) — should appear first
    $skuCritical = Sku::factory()->create(['supplier_id' => $supplier->id, 'lead_time_days' => 7]);
    InventoryDecision::create([
        'sku_id' => $skuCritical->id, 'run_at' => $runAt, 'decision' => 'order',
        'recommended_qty' => 24, 'constrained_qty' => 24,
        'reasoning' => [], 'forecast_demand' => 3.2, 'days_of_cover' => 3.0, 'reorder_point' => 25.0,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->where('decisions.0.decision', 'order')
        ->where('decisions.0.days_of_cover', 3.0)
        ->where('decisions.1.decision', 'hold')
    );
});

it('sends lastRun data when an engine run exists', function () {
    $user = User::factory()->create();

    \App\Models\EngineRun::create([
        'triggered_by'    => $user->id,
        'run_at'          => now()->subHours(2),
        'status'          => 'completed',
        'decisions_count' => 11,
        'duration_ms'     => 500,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('lastRun.run_at')
            ->has('lastRun.decisions_count')
            ->has('lastRun.status')
        );
});

it('sends null lastRun when no runs exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page->where('lastRun', null));
});

it('sends dead stock skus with zero sales in last 30 days', function () {
    $user     = User::factory()->create();
    $supplier = Supplier::factory()->create(['avg_lead_time_days' => 7, 'lead_time_stddev' => 1.0]);

    // SKU with stock but no recent sales = dead stock
    $deadSku = Sku::factory()->create([
        'supplier_id'   => $supplier->id,
        'current_stock' => 20,
    ]);

    // SKU with recent sales = NOT dead stock
    $liveSku = Sku::factory()->create([
        'supplier_id'   => $supplier->id,
        'current_stock' => 30,
    ]);
    \App\Models\SalesHistory::create([
        'sku_id'        => $liveSku->id,
        'sale_date'     => now()->subDays(5),
        'quantity_sold' => 3,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('deadStock', 1)
            ->where('deadStock.0.id', $deadSku->id)
        );
});
