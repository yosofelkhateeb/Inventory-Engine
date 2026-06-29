<?php

namespace Database\Seeders;

use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Synthetic\SeaDatasetSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Top-level demo seeder.
 *
 * Wires the tenant, roles, demo users, and the SEA 30-SKU / 30-month
 * synthetic Shopify-shape dataset. The dataset itself comes from
 * SeaDatasetSeeder (see app/Services/Synthetic/) — this class is just
 * the entry point Laravel's `db:seed` machinery calls.
 *
 * Idempotency: re-running on an already-seeded database is a no-op for
 * the SEA dataset (SKU code prefix check). `migrate:fresh --seed`
 * remains the canonical path; the check just protects accidental
 * `db:seed` repeats during dev.
 *
 * History: the original seeder hand-rolled 11 SKUs and 12 months of
 * flat sales. That logic was retired in the synthetic-dataset milestone
 * (May 2026) — the engine and uplift pipeline now train on the realistic
 * SEA dataset by default.
 */
class SyntheticDataSeeder extends Seeder
{
    public function run(): void
    {
        // Default tenant.
        Tenant::firstOrCreate(
            ['id' => 1],
            ['name' => 'Demo Client', 'locale' => 'en', 'currency' => 'MYR']
        );

        // Roles.
        $ownerRole     = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $warehouseRole = Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        // Demo users.
        $owner = User::firstOrCreate(
            ['email' => 'owner@demo.test'],
            ['name' => 'Demo Owner', 'password' => Hash::make('password'), 'tenant_id' => 1]
        );
        $owner->syncRoles([$ownerRole]);

        $warehouse = User::firstOrCreate(
            ['email' => 'warehouse@demo.test'],
            ['name' => 'Demo Warehouse', 'password' => Hash::make('password'), 'tenant_id' => 1]
        );
        $warehouse->syncRoles([$warehouseRole]);

        // Idempotency: skip the dataset generation if SEA SKUs are already
        // present. `migrate:fresh --seed` rebuilds from scratch and bypasses
        // this branch; the check just protects accidental `db:seed` repeats.
        if (Sku::where('sku_code', 'like', 'SEA-%')->exists()) {
            $this->command?->info('SEA SKUs already present — skipping dataset generation.');
            return;
        }

        // Generate the 30-SKU / 30-month SEA Shopify-shape dataset.
        $result = (new SeaDatasetSeeder())->seed();

        $this->command?->info(sprintf(
            'SEA dataset seeded: %d suppliers, %d SKUs, %d sales rows, %d promos (%d sales rows boosted).',
            $result['suppliers_created'],
            $result['skus_created'],
            $result['sales_rows_written'],
            $result['promos_created'],
            $result['sales_rows_boosted'],
        ));
    }
}
