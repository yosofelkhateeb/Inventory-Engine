<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each stockout episode (effective_position dropped to 0 and
 * stayed there until the next replenishment arrived). The "positive
 * class" for calibration: a stockout that occurred after a HOLD
 * decision is a false negative the watch threshold should have caught.
 *
 * `recovered_at` is null while a stockout is in progress.
 *
 * Populated by:
 * - The synthetic InventorySimulator (records when stock hits zero
 *   and when it's restocked)
 * - Future: real-time observation when on-hand stock from the
 *   Shopify sync hits zero (Chunk 4)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stockout_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_at');
            $table->date('recovered_at')->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable(); // null while in-progress
            $table->unsignedInteger('demand_lost_units')->default(0);  // demand we couldn't satisfy
            $table->string('source', 32)->default('synthetic');
            $table->timestamps();

            $table->index(['tenant_id', 'sku_id', 'occurred_at'], 'so_sku_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stockout_events');
    }
};
