<?php

use App\Models\Promotion;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Helper: create an owner-role user for write tests.
 * PromotionController write methods are owner-gated to match every other
 * write controller in the app (IngestionController, ShopifyController,
 * SettingsController, InventoryDecisionController).
 */
function promotionOwner(): User
{
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    return $user;
}

it('lists promotions for any authenticated user', function () {
    $user = User::factory()->create();

    Promotion::create([
        'name'                => 'Eid Sale',
        'start_date'          => '2026-03-29',
        'end_date'            => '2026-04-01',
        'expected_uplift_pct' => 35.0,
        'affects_all_skus'    => true,
    ]);

    $this->actingAs($user)
        ->get('/promotions')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Promotions/Index')
            ->has('promotions', 1)
            ->has('skus')
        );
});

it('owner can create a promotion affecting all skus', function () {
    $user = promotionOwner();

    $this->actingAs($user)
        ->post('/promotions', [
            'name'                => 'National Day',
            'start_date'          => '2026-09-20',
            'end_date'            => '2026-09-25',
            'expected_uplift_pct' => 20.0,
            'affects_all_skus'    => true,
        ])
        ->assertRedirect();

    expect(Promotion::count())->toBe(1);
    expect(Promotion::first()->name)->toBe('National Day');
    expect(Promotion::first()->affects_all_skus)->toBeTrue();
});

it('owner can create a promotion with specific skus', function () {
    $user     = promotionOwner();
    $supplier = Supplier::factory()->create();
    $sku1     = Sku::factory()->create(['supplier_id' => $supplier->id]);
    $sku2     = Sku::factory()->create(['supplier_id' => $supplier->id]);

    $this->actingAs($user)
        ->post('/promotions', [
            'name'                => 'Accessory Flash Sale',
            'start_date'          => '2026-05-01',
            'end_date'            => '2026-05-03',
            'expected_uplift_pct' => 15.0,
            'affects_all_skus'    => false,
            'sku_ids'             => [$sku1->id, $sku2->id],
        ])
        ->assertRedirect();

    $promotion = Promotion::first();
    expect($promotion)->not->toBeNull();
    expect($promotion->affects_all_skus)->toBeFalse();
    expect($promotion->skus()->count())->toBe(2);
});

it('owner can delete a promotion', function () {
    $user = promotionOwner();

    $promotion = Promotion::create([
        'name'                => 'To Delete',
        'start_date'          => '2026-06-01',
        'end_date'            => '2026-06-05',
        'expected_uplift_pct' => 10.0,
        'affects_all_skus'    => true,
    ]);

    $this->actingAs($user)
        ->delete("/promotions/{$promotion->id}")
        ->assertRedirect();

    expect(Promotion::count())->toBe(0);
    // Soft delete — record still in DB with deleted_at set
    expect(Promotion::withTrashed()->count())->toBe(1);
});

it('rejects out-of-range manual override values', function () {
    // expected_uplift_pct is no longer a user-supplied field — it's the
    // engine's output column written by PredictionEngine on save. The
    // operator-supplied analog is manual_uplift_pct (the override).
    $user = promotionOwner();

    $this->actingAs($user)
        ->post('/promotions', [
            'name'              => 'Invalid override',
            'start_date'        => '2026-06-01',
            'end_date'          => '2026-06-05',
            'manual_uplift_pct' => 999,  // exceeds max 500
            'override_reason'   => 'Testing the validator',
            'affects_all_skus'  => true,
        ])
        ->assertSessionHasErrors('manual_uplift_pct');
});

it('requires override_reason when manual_uplift_pct is supplied', function () {
    $user = promotionOwner();

    $this->actingAs($user)
        ->post('/promotions', [
            'name'              => 'Override without reason',
            'start_date'        => '2026-06-01',
            'end_date'          => '2026-06-05',
            'manual_uplift_pct' => 35,
            // override_reason intentionally missing
            'affects_all_skus'  => true,
        ])
        ->assertSessionHasErrors('override_reason');
});

it('writes the engine prediction to expected_uplift_pct when no manual override is supplied', function () {
    $user = promotionOwner();

    $this->actingAs($user)
        ->post('/promotions', [
            'name'              => 'Engine-driven uplift',
            'promotion_type'    => 'flash',
            'discount_pct'      => 30,
            'ad_spend_band'     => 'high',
            'start_date'        => '2026-06-01',
            'end_date'          => '2026-06-05',
            'affects_all_skus'  => true,
        ])
        ->assertRedirect();

    $promo = Promotion::where('name', 'Engine-driven uplift')->first();
    expect($promo)->not->toBeNull()
        ->and($promo->expected_uplift_pct)->toBeGreaterThan(0.0); // engine computed something
});

it('honors the manual override over the engine prediction', function () {
    $user = promotionOwner();

    $this->actingAs($user)
        ->post('/promotions', [
            'name'              => 'Override wins',
            'promotion_type'    => 'flash',
            'discount_pct'      => 30,
            'manual_uplift_pct' => 42,
            'override_reason'   => 'Insider knowledge: 3x ad spend this round',
            'start_date'        => '2026-06-01',
            'end_date'          => '2026-06-05',
            'affects_all_skus'  => true,
        ])
        ->assertRedirect();

    $promo = Promotion::where('name', 'Override wins')->first();
    expect((float) $promo->expected_uplift_pct)->toBe(42.0)
        ->and($promo->override_reason)->toContain('3x ad spend');
});

it('redirects guests to login', function () {
    $this->get('/promotions')->assertRedirect('/login');
});

it('non-owner cannot create a promotion', function () {
    Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('warehouse');

    $this->actingAs($user)
        ->post('/promotions', [
            'name'                => 'Should Be Blocked',
            'start_date'          => '2026-09-20',
            'end_date'            => '2026-09-25',
            'expected_uplift_pct' => 20.0,
            'affects_all_skus'    => true,
        ])
        ->assertForbidden();

    expect(Promotion::count())->toBe(0);
});

it('non-owner cannot update a promotion', function () {
    Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('warehouse');

    $promotion = Promotion::create([
        'name'                => 'Original',
        'start_date'          => '2026-06-01',
        'end_date'            => '2026-06-05',
        'expected_uplift_pct' => 10.0,
        'affects_all_skus'    => true,
    ]);

    $this->actingAs($user)
        ->patch("/promotions/{$promotion->id}", [
            'expected_uplift_pct' => 500,
        ])
        ->assertForbidden();

    expect($promotion->fresh()->expected_uplift_pct)->toBe(10.0);
});

it('non-owner cannot delete a promotion', function () {
    Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('warehouse');

    $promotion = Promotion::create([
        'name'                => 'Should Survive',
        'start_date'          => '2026-06-01',
        'end_date'            => '2026-06-05',
        'expected_uplift_pct' => 10.0,
        'affects_all_skus'    => true,
    ]);

    $this->actingAs($user)
        ->delete("/promotions/{$promotion->id}")
        ->assertForbidden();

    expect(Promotion::count())->toBe(1);
});

it('user with no role cannot create a promotion', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->post('/promotions', [
            'name'                => 'No Role Attempt',
            'start_date'          => '2026-09-20',
            'end_date'            => '2026-09-25',
            'expected_uplift_pct' => 20.0,
            'affects_all_skus'    => true,
        ])
        ->assertForbidden();

    expect(Promotion::count())->toBe(0);
});

it('suggest-uplift endpoint accepts a Campaign Brief and returns the engine prediction shape', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $response = $this->actingAs($user)
        ->getJson('/promotions/suggest-uplift?' . http_build_query([
            'promotion_type'         => 'flash',
            'discount_pct'           => 30,
            'discount_type'          => 'percent_off',
            'channel_mix'            => ['paid_social', 'email'],
            'ad_spend_band'          => 'high',
            'audience'               => 'both',
            'lead_announcement_days' => 7,
        ]))
        ->assertOk();

    // Cold-start: no Brief-tagged history yet → orchestrator routes to
    // Layer 1 (rules). Response carries the engine's full output shape.
    $response->assertJsonStructure(['value', 'lower', 'upper', 'basis', 'sample_size', 'layer'])
        ->assertJson(['layer' => 'rules', 'sample_size' => 0]);
});

it('suggest-uplift endpoint validates Brief enums', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->getJson('/promotions/suggest-uplift?ad_spend_band=enormous')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ad_spend_band']);
});

it('suggest-uplift endpoint accepts legacy v1 inputs without breaking', function () {
    // The pre-Step-6 frontend still sends sku_ids / categories /
    // promotion_type. The controller accepts them; only promotion_type
    // is mapped into the Brief — sku_ids/categories are ignored. This
    // keeps the v1 dialog functional during the transition window.
    $user = User::factory()->create(['tenant_id' => 1]);

    $this->actingAs($user)
        ->getJson('/promotions/suggest-uplift?' . http_build_query([
            'sku_ids'        => [1, 2, 3],
            'categories'     => ['accessory'],
            'promotion_type' => 'seasonal',
        ]))
        ->assertOk()
        ->assertJsonStructure(['value', 'layer']);
});
