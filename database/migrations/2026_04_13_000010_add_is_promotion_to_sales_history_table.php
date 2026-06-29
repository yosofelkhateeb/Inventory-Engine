<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->boolean('is_promotion')->default(false)->after('quantity_sold');
        });
    }

    public function down(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->dropColumn('is_promotion');
        });
    }
};
