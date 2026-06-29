<?php

use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Services\InventoryEngine\DecisionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Creates an InventoryDecision with a given status (default: pending).
// status is not in $fillable, so terminal/non-default statuses are forced via DB.
function makeDecisionWithStatus(Sku $sku, string $status = 'pending'): InventoryDecision
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

    if ($status !== 'pending') {
        \DB::table('inventory_decisions')
            ->where('id', $decision->id)
            ->update(['status' => $status]);
    }

    return $decision;
}

it('does nothing when no decisions exist for the sku', function () {
    $sku     = Sku::factory()->create();
    $service = new DecisionStatusService();

    $service->supersedePending($sku->id, $sku->tenant_id);

    expect(
        InventoryDecision::withoutGlobalScopes()->where('sku_id', $sku->id)->count()
    )->toBe(0);
});

it('supersedes a pending decision on engine re-run', function () {
    $sku     = Sku::factory()->create();
    $service = new DecisionStatusService();

    makeDecisionWithStatus($sku, 'pending');
    $service->supersedePending($sku->id, $sku->tenant_id);

    $record = InventoryDecision::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($record->status)->toBe('superseded')
        ->and($record->status_changed_at)->not->toBeNull()
        ->and($record->status_changed_by)->toBeNull()
        ->and($record->status_notes)->toStartWith('Superseded by new engine run on');
});

it('supersedes an acknowledged decision on engine re-run', function () {
    $sku     = Sku::factory()->create();
    $service = new DecisionStatusService();

    makeDecisionWithStatus($sku, 'acknowledged');
    $service->supersedePending($sku->id, $sku->tenant_id);

    $record = InventoryDecision::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->first();

    expect($record->status)->toBe('superseded');
});

it('does not touch terminal status decisions', function () {
    $sku     = Sku::factory()->create();
    $service = new DecisionStatusService();

    makeDecisionWithStatus($sku, 'received');
    makeDecisionWithStatus($sku, 'ignored');
    makeDecisionWithStatus($sku, 'superseded');

    $service->supersedePending($sku->id, $sku->tenant_id);

    $counts = InventoryDecision::withoutGlobalScopes()
        ->where('sku_id', $sku->id)
        ->get()
        ->groupBy('status')
        ->map->count();

    expect($counts->get('received'))->toBe(1)
        ->and($counts->get('ignored'))->toBe(1)
        ->and($counts->get('superseded'))->toBe(1);
});
