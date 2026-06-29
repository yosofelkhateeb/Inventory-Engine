<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->enum('abc_class', ['A', 'B', 'C'])->nullable()->after('lead_time_days');
            $table->enum('xyz_class', ['X', 'Y', 'Z'])->nullable()->after('abc_class');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['abc_class', 'xyz_class']);
        });
    }
};
