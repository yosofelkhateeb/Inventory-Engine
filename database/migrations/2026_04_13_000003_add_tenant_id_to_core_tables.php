<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'suppliers',
            'skus',
            'sales_history',
            'inventory_decisions',
            'purchase_orders',
            'engine_runs',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('tenant_id')
                    ->default(1)
                    ->after('id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'engine_runs',
            'purchase_orders',
            'inventory_decisions',
            'sales_history',
            'skus',
            'suppliers',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign([$table.'_tenant_id_foreign']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
