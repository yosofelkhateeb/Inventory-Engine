<?php

use App\Jobs\RunShopifyInitialLoadJob;
use App\Jobs\RunShopifyIncrementalSyncJob;
use App\Models\IngestionCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

it('owner can connect shopify and dispatches initial load job', function () {
    Queue::fake();
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $this->actingAs($user)
        ->postJson('/ingestion/shopify/connect', [
            'shop_domain'  => 'acme-test.myshopify.com',
            'access_token' => 'shpat_faketoken123',
        ])
        ->assertRedirect();

    Queue::assertPushed(RunShopifyInitialLoadJob::class);

    $cred = IngestionCredential::withoutGlobalScopes()
        ->where('tenant_id', 1)
        ->where('source', 'shopify')
        ->first();

    expect($cred)->not->toBeNull()
        ->and($cred->is_active)->toBeTrue()
        ->and($cred->credentials['shop_domain'])->toBe('acme-test.myshopify.com');
});

it('non-owner cannot connect shopify', function () {
    Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('warehouse');

    $this->actingAs($user)
        ->postJson('/ingestion/shopify/connect', [
            'shop_domain'  => 'acme-test.myshopify.com',
            'access_token' => 'shpat_faketoken123',
        ])
        ->assertForbidden();
});

it('rejects invalid shopify domain format', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    $this->actingAs($user)
        ->postJson('/ingestion/shopify/connect', [
            'shop_domain'  => 'not-a-shopify-domain.com',
            'access_token' => 'shpat_faketoken123',
        ])
        ->assertUnprocessable();
});

it('owner can disconnect shopify', function () {
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    IngestionCredential::withoutGlobalScopes()->create([
        'tenant_id'   => 1,
        'source'      => 'shopify',
        'credentials' => ['shop_domain' => 'acme-test.myshopify.com', 'access_token' => 'tok'],
        'is_active'   => true,
    ]);

    $this->actingAs($user)
        ->deleteJson('/ingestion/shopify/disconnect')
        ->assertRedirect();

    expect(
        IngestionCredential::withoutGlobalScopes()
            ->where('tenant_id', 1)
            ->where('source', 'shopify')
            ->value('is_active')
    )->toBeFalse();
});

it('owner can trigger manual sync', function () {
    Queue::fake();
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $user = User::factory()->create(['tenant_id' => 1]);
    $user->assignRole('owner');

    IngestionCredential::withoutGlobalScopes()->create([
        'tenant_id'   => 1,
        'source'      => 'shopify',
        'credentials' => ['shop_domain' => 'acme-test.myshopify.com', 'access_token' => 'tok'],
        'is_active'   => true,
    ]);

    $this->actingAs($user)
        ->postJson('/ingestion/shopify/sync')
        ->assertRedirect();

    Queue::assertPushed(RunShopifyIncrementalSyncJob::class);
});

it('ingestion page exposes shopify connection status', function () {
    $user = User::factory()->create(['tenant_id' => 1]);

    IngestionCredential::withoutGlobalScopes()->create([
        'tenant_id'    => 1,
        'source'       => 'shopify',
        'credentials'  => ['shop_domain' => 'acme-test.myshopify.com', 'access_token' => 'tok'],
        'is_active'    => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/ingestion')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('shopify.connected', true)
            ->where('shopify.shop_domain', 'acme-test.myshopify.com')
        );
});

it('shopify source transform maps variant fields correctly', function () {
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');

    $raw = [
        'sku_code'      => 'FB-001',
        'name'          => 'Grip Socks',
        'product_type'  => 'Accessory',
        'current_stock' => 50,
        'price'         => '45.00',
    ];

    $result = $source->transform('skus', $raw);

    expect($result['sku_code'])->toBe('FB-001')
        ->and($result['category'])->toBe('accessory')
        ->and($result['current_stock'])->toBe(50)
        ->and($result['unit_cost'])->toBe(4500); // 45.00 SAR × 100
});

it('shopify source transform maps order line items correctly', function () {
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');

    $raw = [
        'sku_code'      => 'FB-001',
        'sale_date'     => '2024-04-01',
        'quantity_sold' => 3,
        'is_promotion'  => true,
    ];

    $result = $source->transform('sales_history', $raw);

    expect($result['sku_code'])->toBe('FB-001')
        ->and($result['sale_date'])->toBe('2024-04-01')
        ->and($result['quantity_sold'])->toBe(3)
        ->and($result['is_promotion'])->toBeTrue();
});

it('incremental sync job skips inactive credentials', function () {
    $cred = IngestionCredential::withoutGlobalScopes()->create([
        'tenant_id'   => 1,
        'source'      => 'shopify',
        'credentials' => ['shop_domain' => 'store.myshopify.com', 'access_token' => 'tok'],
        'is_active'   => false,
    ]);

    Http::fake(); // Should not be called

    (new RunShopifyIncrementalSyncJob($cred->id))->handle();

    Http::assertNothingSent();
});

it('incremental sync job pulls orders and upserts sales history', function () {
    $cred = IngestionCredential::withoutGlobalScopes()->create([
        'tenant_id'        => 1,
        'source'           => 'shopify',
        'credentials'      => ['shop_domain' => 'store.myshopify.com', 'access_token' => 'tok'],
        'is_active'        => true,
        'last_sync_cursor' => '2024-04-01T00:00:00Z',
    ]);

    $supplier = \App\Models\Supplier::withoutGlobalScopes()->create([
        'tenant_id' => 1, 'name' => 'Acme', 'avg_lead_time_days' => 7,
    ]);

    \App\Models\Sku::withoutGlobalScopes()->create([
        'tenant_id' => 1, 'supplier_id' => $supplier->id,
        'sku_code' => 'FB-001', 'name' => 'Grip Socks', 'category' => 'accessory',
        'moq' => 10, 'unit_cost' => 1000, 'reorder_qty' => 10,
        'current_stock' => 50, 'lead_time_days' => 7,
    ]);

    Http::fake([
        'https://store.myshopify.com/admin/api/2024-01/orders.json*' => Http::response([
            'orders' => [[
                'created_at'     => '2024-04-15T10:00:00Z',
                'discount_codes' => [],
                'line_items'     => [[
                    'sku'      => 'FB-001',
                    'quantity' => 5,
                ]],
            ]],
        ], 200),
    ]);

    (new RunShopifyIncrementalSyncJob($cred->id))->handle();

    $cred->refresh();
    expect($cred->last_sync_at)->not->toBeNull();

    $row = \App\Models\SalesHistory::withoutGlobalScopes()
        ->where('tenant_id', 1)
        ->where('sale_date', '2024-04-15')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->quantity_sold)->toBe(5);
});

/**
 * Helper: invoke the private nextPageUrl method via Reflection.
 * Defence-in-depth check (CSO finding T1, 2026-05-02): the method must
 * reject Link headers pointing at a host that isn't the connected
 * shop_domain so the X-Shopify-Access-Token isn't sent off-domain.
 */
function callNextPageUrl(\App\Services\Ingestion\Sources\ShopifySource $source, ?string $linkHeader): ?string
{
    $ref = new \ReflectionClass($source);
    $method = $ref->getMethod('nextPageUrl');
    $method->setAccessible(true);
    return $method->invoke($source, $linkHeader);
}

it('nextPageUrl returns null when the Link header is empty', function () {
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');
    expect(callNextPageUrl($source, null))->toBeNull();
    expect(callNextPageUrl($source, ''))->toBeNull();
});

it('nextPageUrl follows a same-host next link', function () {
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');
    $header = '<https://store.myshopify.com/admin/api/2024-01/products.json?page_info=abc>; rel="next"';
    expect(callNextPageUrl($source, $header))->toBe('https://store.myshopify.com/admin/api/2024-01/products.json?page_info=abc');
});

it('nextPageUrl rejects an off-host next link to prevent token exfiltration', function () {
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');
    // Crafted Link header pointing at attacker-controlled host. If we
    // followed it, the next shopifyRequest() would send the access token
    // to attacker.example. Defence-in-depth — must return null.
    $header = '<https://attacker.example/admin/api/2024-01/products.json?page_info=abc>; rel="next"';
    expect(callNextPageUrl($source, $header))->toBeNull();
});

it('nextPageUrl rejects a same-domain-different-shop next link', function () {
    // Two different myshopify.com tenants. Even though both are inside
    // Shopify's domain, the access token only authorises us against
    // store.myshopify.com — sending it to other-store.myshopify.com is
    // still cross-tenant token leakage.
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');
    $header = '<https://other-store.myshopify.com/admin/api/2024-01/products.json>; rel="next"';
    expect(callNextPageUrl($source, $header))->toBeNull();
});

it('nextPageUrl rejects a Link header without a next relation', function () {
    $source = new \App\Services\Ingestion\Sources\ShopifySource('store.myshopify.com', 'tok');
    // Shopify sometimes returns a `previous`-only header at the last page.
    $header = '<https://store.myshopify.com/admin/api/2024-01/products.json?page_info=zzz>; rel="previous"';
    expect(callNextPageUrl($source, $header))->toBeNull();
});
