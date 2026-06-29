<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            // equipment | accessory | bundle — drives forecast threshold selection
            $table->enum('category', ['equipment', 'accessory', 'bundle'])
                ->default('accessory')
                ->after('sku_code');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
