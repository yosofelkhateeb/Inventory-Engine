<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-supplied override for the safety-stock multiplier the engine
 * derives from ABC/XYZ classification. NULL means "use engine default";
 * a numeric value short-circuits AbcXyzClassifier::getSafetyStockMultiplier
 * for this SKU.
 *
 * Range in practice: 0.5 (low buffer) to 2.5 (heavy buffer for critical SKUs).
 * decimal(4,2) gives plenty of room without invalidating sane operator entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->decimal('safety_stock_multiplier_override', 4, 2)
                ->nullable()
                ->after('xyz_class');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn('safety_stock_multiplier_override');
        });
    }
};
