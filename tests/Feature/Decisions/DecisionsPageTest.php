<?php

use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Create a decision forced into the given status. Mirrors
 * tests/Feature/InventoryEngine/DecisionStatusTransitionTest::makeDecision —
 * `status` is intentionally not fillable, so transitions go via
 * DecisionStatusService in prod and via raw DB update in tests.
 */
function makeDecisionForPageTest(Sku $sku, string $status, string $decision = 'order'): InventoryDecision
{
    $d = InventoryDecision::create([
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => $decision,
        'recommended_qty' => 10,
        'constrained_qty' => 10,
        'reasoning'       => [],
        'forecast_demand' => 1.0,
        'days_of_cover'   => 5.0,
        'reorder_point'   => 5.0,
    ]);

    DB::table('inventory_decisions')->where('id', $d->id)->update(['status' => $status]);

    return $d->refresh();
}

it('default tab is Pending — only status=pending rows', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    foreach (InventoryDecision::STATUSES as $status) {
        makeDecisionForPageTest($sku, $status);
    }

    // No tab query param → defaults to Pending. Only the single pending row
    // should appear; acknowledged/ordered/in_transit live on the In Flight
    // tab; received/ignored/superseded show only via the Audit Log.
    actingAs($user)
        ->get('/decisions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Decisions/Index')
            ->has('decisions.data', 1)
            ->where('decisions.data.0.status', 'pending')
            ->where('activeTab', 'pending')
        );
});

it('In Flight tab shows acknowledged / ordered / in_transit rows only', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    foreach (InventoryDecision::STATUSES as $status) {
        makeDecisionForPageTest($sku, $status);
    }

    actingAs($user)
        ->get('/decisions?tab=in_flight')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Decisions/Index')
            ->has('decisions.data', 3)
            ->where('activeTab', 'in_flight')
        );
});

it('SKU deeplink overrides the tab filter so the row is found regardless of status', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id, 'sku_code' => 'FB-TEST']);

    // Only an acknowledged row exists for this SKU — outside the default
    // Pending tab. The deeplink should still surface it.
    makeDecisionForPageTest($sku, 'acknowledged');

    actingAs($user)
        ->get('/decisions?sku=FB-TEST')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Decisions/Index')
            ->has('decisions.data', 1)
            ->where('decisions.data.0.status', 'acknowledged')
        );
});

it('renders the Decisions page when there are no decisions', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/decisions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Decisions/Index')
            ->has('decisions.data', 0)
        );
});

it('filters by decision type when the decision query param is passed', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $sku = Sku::factory()->create(['supplier_id' => $supplier->id]);

    makeDecisionForPageTest($sku, 'pending', decision: 'order');
    makeDecisionForPageTest($sku, 'pending', decision: 'hold');

    actingAs($user)
        ->get('/decisions?decision=order')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('decisions.data', 1)
            ->where('decisions.data.0.decision', 'order')
        );
});

it('redirects guests to login', function () {
    $this->get('/decisions')->assertRedirect('/login');
});
