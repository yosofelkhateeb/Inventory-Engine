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
        Schema::table('promotions', function (Blueprint $table) {
            $table->enum('promotion_type', ['seasonal', 'flash', 'clearance', 'bundle', 'other'])
                ->nullable()
                ->after('name');

            // JSON array of category strings when targeting by category, e.g. ["equipment","accessory"].
            // Null/empty → targets all SKUs (affects_all_skus=true) or specific SKUs via promotion_skus.
            $table->json('applies_to_categories')->nullable()->after('affects_all_skus');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['promotion_type', 'applies_to_categories']);
        });
    }
};
