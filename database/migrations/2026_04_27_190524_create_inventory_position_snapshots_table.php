<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily per-SKU snapshot of inventory state. Lets us reconstruct what
 * the engine WOULD have decided each day — the training set for the
 * calibration job in Chunk 3.
 *
 * One row per (tenant_id, sku_id, snapshot_date). Populated daily by
 * the inventory engine in production; pre-populated by the synthetic
 * simulator across 3 years of history for cold-start training.
 *
 * Each row captures the inputs to the watch decision so we can re-run
 * candidate (k_lead, k_ltv, k_smape, k_trend) tuples against the
 * historical snapshots and score them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_position_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');

            // State at end of day
            $table->integer('on_hand');
            $table->integer('in_transit')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('effective_position');
            $table->integer('reorder_point');
            $table->decimal('daily_demand', 10, 4);
            $table->decimal('demand_stddev', 10, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('lead_time_stddev', 10, 4)->nullable();
            $table->decimal('smape', 8, 2)->nullable();
            $table->string('trend_direction', 16)->nullable();

            // What decision the engine produced for this snapshot
            // ('order' | 'watch' | 'hold' | 'order_budget_blocked')
            $table->string('decision', 24);

            // Outcome window — populated retroactively by Chunk 3
            // calibration. NULL until we know what happened next.
            $table->boolean('reorder_within_threshold')->nullable();
            $table->boolean('stockout_within_threshold')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'sku_id', 'snapshot_date'], 'ips_unique_per_day');
            $table->index(['tenant_id', 'snapshot_date'], 'ips_tenant_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_position_snapshots');
    }
};
