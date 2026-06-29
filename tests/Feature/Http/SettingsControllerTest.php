<?php

use App\Models\SystemSetting;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('renders the settings page for any authenticated user', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Settings/Index')
            ->has('settings')
            ->where('canEdit', false)
        );
});

it('renders settings page with canEdit true for owners', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('canEdit', true));
});

it('owner can update settings', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    SystemSetting::withoutGlobalScopes()->create([
        'tenant_id' => 1,
        'key'       => 'forecast.bias_drift_threshold_pct',
        'value'     => '15',
        'group'     => 'forecasting',
    ]);

    $this->actingAs($user)
        ->patchJson('/settings', [
            'settings' => ['forecast.bias_drift_threshold_pct' => 20],
        ])
        ->assertRedirect();

    $saved = SystemSetting::withoutGlobalScopes()
        ->where('tenant_id', 1)
        ->where('key', 'forecast.bias_drift_threshold_pct')
        ->value('value');

    expect($saved)->toBe('20');
});

it('non-owner cannot update settings', function () {
    Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('warehouse');

    $this->actingAs($user)
        ->patchJson('/settings', [
            'settings' => ['forecast.bias_drift_threshold_pct' => 99],
        ])
        ->assertForbidden();
});

it('rejects negative threshold values', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $this->actingAs($user)
        ->patchJson('/settings', [
            'settings' => ['forecast.bias_drift_threshold_pct' => -5],
        ])
        ->assertUnprocessable();
});

it('accepts negative k_trend (the only signed coefficient)', function () {
    // k_trend can be negative — declining-demand SKUs use a negative
    // coefficient to narrow the watch buffer. The validator must allow
    // signed values for this specific key while still rejecting
    // negatives for percentage / threshold keys.
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $this->actingAs($user)
        ->patchJson('/settings', [
            'settings' => ['decision.watch.k_trend' => -1.5],
        ])
        ->assertRedirect();
});
