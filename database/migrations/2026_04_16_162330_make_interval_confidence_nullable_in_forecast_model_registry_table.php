<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make interval_confidence nullable so that null can represent
     * "not computed" (distinct from the DB default of 0.95).
     * The DB default still applies when the column is omitted on insert.
     */
    public function up(): void
    {
        Schema::table('forecast_model_registry', function (Blueprint $table) {
            $table->decimal('interval_confidence', 4, 2)->nullable()->default(0.95)->change();
        });
    }

    public function down(): void
    {
        Schema::table('forecast_model_registry', function (Blueprint $table) {
            $table->decimal('interval_confidence', 4, 2)->default(0.95)->change();
        });
    }
};
