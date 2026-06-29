<?php

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Models\User;
use App\Services\InventoryEngine\DecisionStatusService;
use Spatie\Permission\Models\Role;

// Helper: create a decision forced into the given status.
function makeDecision(Sku $sku, string $status = 'pending'): InventoryDecision
{
    $decision = InventoryDecision::create([
        'sku_id'          => $sku->id,
        'run_at'          => now(),
        'decision'        => 'order',
        'recommended_qty' => 10,
        'constrained_qty' => 10,
        'reasoning'       => [],
        'forecast_demand' => 1.0,
        'days_of_cover'   => 5.0,
        'reorder_point'   => 5.0,
    ]);

    \DB::table('inventory_decisions')
        ->where('id', $decision->id)
        ->update(['status' => $status]);
    $decision->refresh();

    return $decision;
}

it('transitions PENDING → ACKNOWLEDGED via the controller endpoint', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $sku      = Sku::factory()->create(['tenant_id' => 1]);
    $decision = makeDecision($sku, 'pending');

    $this->actingAs($user)
        ->patchJson("/inventory-decisions/{$decision->id}/status", ['action' => 'acknowledge'])
        ->assertOk()
        ->assertJsonFragment(['status' => 'acknowledged']);

    expect(
        InventoryDecision::withoutGlobalScopes()->find($decision->id)->status
    )->toBe('acknowledged');
});

it('runs the full transition chain PENDING → ACKNOWLEDGED → ORDERED → IN_TRANSIT → RECEIVED', function () {
    $service = new DecisionStatusService();
    $user    = User::factory()->create();
    $sku     = Sku::factory()->create();

    $decision = makeDecision($sku, 'pending');

    $service->acknowledge($decision, $user);
    $decision->refresh();
    expect($decision->status)->toBe('acknowledged');

    $service->markOrdered($decision, $user);
    $decision->refresh();
    expect($decision->status)->toBe('ordered');

    $service->markInTransit($decision, $user);
    $decision->refresh();
    expect($decision->status)->toBe('in_transit');

    $service->markReceived($decision, $user);
    $decision->refresh();
    expect($decision->status)->toBe('received');
});

it('blocks any user-initiated transition out of SUPERSEDED', function () {
    // SUPERSEDED is engine-managed only — operators must not be able to
    // resurrect a superseded recommendation through the public API.
    $service  = new DecisionStatusService();
    $user     = User::factory()->create();
    $sku      = Sku::factory()->create();
    $decision = makeDecision($sku, 'superseded');

    expect(fn () => $service->acknowledge($decision, $user))
        ->toThrow(InvalidStatusTransitionException::class);
});

it('allows walking back from a previously terminal status (received → acknowledged)', function () {
    // Operators may correct mistakes by walking a recommendation back across
    // the active lifecycle. The frontend surfaces a confirmation dialog for
    // backward moves; the service is permissive so the audit log still records
    // the change.
    $service  = new DecisionStatusService();
    $user     = User::factory()->create();
    $sku      = Sku::factory()->create();
    $decision = makeDecision($sku, 'received');

    $service->acknowledge($decision, $user);
    $decision->refresh();
    expect($decision->status)->toBe('acknowledged');
});

it('allows skip-ahead transitions (pending → ordered without going through acknowledged)', function () {
    $service  = new DecisionStatusService();
    $user     = User::factory()->create();
    $sku      = Sku::factory()->create();
    $decision = makeDecision($sku, 'pending');

    $service->markOrdered($decision, $user);
    $decision->refresh();
    expect($decision->status)->toBe('ordered');
});

it('persists operator-supplied qty on Mark Ordered via the controller endpoint', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $sku      = Sku::factory()->create(['tenant_id' => 1]);
    $decision = makeDecision($sku, 'pending');
    expect($decision->recommended_qty)->toBe(10);

    $this->actingAs($user)
        ->patchJson("/inventory-decisions/{$decision->id}/status", [
            'action' => 'order',
            'qty'    => 25,
        ])
        ->assertOk();

    $row = InventoryDecision::withoutGlobalScopes()->find($decision->id);
    expect($row->status)->toBe('ordered')
        ->and($row->ordered_qty)->toBe(25);
});

it('persists operator-supplied qty on Mark Received via the controller endpoint', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $sku      = Sku::factory()->create(['tenant_id' => 1]);
    $decision = makeDecision($sku, 'in_transit');
    \DB::table('inventory_decisions')->where('id', $decision->id)->update(['ordered_qty' => 30]);

    $this->actingAs($user)
        ->patchJson("/inventory-decisions/{$decision->id}/status", [
            'action' => 'receive',
            'qty'    => 28,
        ])
        ->assertOk();

    $row = InventoryDecision::withoutGlobalScopes()->find($decision->id);
    expect($row->status)->toBe('received')
        ->and($row->received_qty)->toBe(28);
});

it('requires a non-empty reason when action is ignore', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $sku      = Sku::factory()->create(['tenant_id' => 1]);
    $decision = makeDecision($sku, 'pending');

    $this->actingAs($user)
        ->patchJson("/inventory-decisions/{$decision->id}/status", ['action' => 'ignore'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($user)
        ->patchJson("/inventory-decisions/{$decision->id}/status", [
            'action' => 'ignore',
            'reason' => 'Stock arrived elsewhere',
        ])
        ->assertOk();

    $row = InventoryDecision::withoutGlobalScopes()->find($decision->id);
    expect($row->status)->toBe('ignored')
        ->and($row->ignored_reason)->toBe('Stock arrived elsewhere');
});

it('flags backward transitions via isBackwardTransition()', function () {
    expect(DecisionStatusService::isBackwardTransition('ordered', 'acknowledged'))->toBeTrue()
        ->and(DecisionStatusService::isBackwardTransition('received', 'pending'))->toBeTrue()
        ->and(DecisionStatusService::isBackwardTransition('pending', 'acknowledged'))->toBeFalse()
        ->and(DecisionStatusService::isBackwardTransition('ordered', 'received'))->toBeFalse();
});

it('stamps status_changed_at, status_changed_by, and appends to status_history', function () {
    $service  = new DecisionStatusService();
    $user     = User::factory()->create();
    $sku      = Sku::factory()->create();
    $decision = makeDecision($sku, 'pending');

    $before = now()->subSecond();
    $service->acknowledge($decision, $user);

    $record = InventoryDecision::withoutGlobalScopes()->find($decision->id);

    expect($record->status_changed_by)->toBe($user->id)
        ->and($record->status_changed_at->greaterThanOrEqualTo($before))->toBeTrue()
        ->and($record->status_history)->toBeArray()
        ->and($record->status_history)->toHaveCount(1)
        ->and($record->status_history[0]['status'])->toBe('acknowledged')
        ->and($record->status_history[0]['by'])->toBe($user->id);
});
