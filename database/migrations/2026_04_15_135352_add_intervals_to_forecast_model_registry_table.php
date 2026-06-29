<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('forecast_model_registry', function (Blueprint $table) {
            $table->decimal('interval_lower', 10, 4)->nullable()->after('smape');
            $table->decimal('interval_upper', 10, 4)->nullable()->after('interval_lower');
            $table->decimal('interval_confidence', 4, 2)->default(0.95)->after('interval_upper');
            $table->decimal('interval_empirical_coverage', 4, 3)->nullable()->after('interval_confidence');
            $table->text('selection_rationale')->nullable()->after('interval_empirical_coverage');
            $table->string('transformation_applied', 16)->default('none')->after('selection_rationale');
            $table->json('hyperparameters')->nullable()->after('transformation_applied');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_model_registry', function (Blueprint $table) {
            $table->dropColumn([
                'interval_lower', 'interval_upper', 'interval_confidence',
                'interval_empirical_coverage', 'selection_rationale',
                'transformation_applied', 'hyperparameters',
            ]);
        });
    }
};
