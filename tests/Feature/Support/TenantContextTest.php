<?php

use App\Models\User;
use App\Support\TenantContext;

// Pest.php's beforeEach binds tenant 1 by default for every Feature test.
// These tests intentionally clear that binding to test the unbound state
// (so the strict throw in TenantContext::tenantId() can be exercised).
beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('falls back to the bound context when no auth user is set', function () {
    auth()->forgetUser();

    TenantContext::set(42);

    expect(TenantContext::tenantId())->toBe(42);
});

it('throws when neither auth nor context is set', function () {
    auth()->forgetUser();
    TenantContext::clear();

    TenantContext::tenantId();
})->throws(\RuntimeException::class, 'no tenant in scope');

it('run() binds tenant for the callback and restores the previous binding', function () {
    auth()->forgetUser();
    TenantContext::set(1);

    $inside = TenantContext::run(99, fn () => TenantContext::tenantId());

    expect($inside)->toBe(99);
    expect(TenantContext::tenantId())->toBe(1);
});

it('run() restores the previous binding even when the callback throws', function () {
    auth()->forgetUser();
    TenantContext::set(1);

    try {
        TenantContext::run(99, function () {
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) {
        // expected
    }

    expect(TenantContext::tenantId())->toBe(1);
});

it('peek() returns null when no context is bound (does not consult auth)', function () {
    auth()->forgetUser();
    TenantContext::clear();

    expect(TenantContext::peek())->toBeNull();
});

it('peek() returns the bound context when set', function () {
    TenantContext::set(7);

    expect(TenantContext::peek())->toBe(7);
});

it('auth user wins over the bound context', function () {
    // RefreshDatabase wipes tenants between tests, so create one explicitly.
    // Mass-assigning `id` requires `forceCreate` or unguard since `id` is not fillable.
    $tenant = \App\Models\Tenant::forceCreate(['id' => 7, 'name' => 'Test Tenant 7']);

    TenantContext::set(99);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    auth()->setUser($user);

    expect(TenantContext::tenantId())->toBe($tenant->id);

    auth()->forgetUser();
});
