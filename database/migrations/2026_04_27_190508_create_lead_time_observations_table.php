<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each individual lead-time event (one row per replenishment
 * order arrival). Replaces the single Sku::lead_time_days scalar with
 * a distribution we can compute statistics over (mean, stddev, p95).
 *
 * Populated by:
 * - The synthetic InventorySimulator during training-dataset generation
 * - Real Shopify connector when an order's status transitions from
 *   "ordered" to "received" (Chunk 4 wiring)
 *
 * Used by:
 * - DecisionScorer's per-SKU watch ceiling (p95 of trailing window)
 * - LeadTimeHandler's variance estimate (replacing the seeded constant)
 * - Calibration job in Chunk 3 (training feature for k_ltv)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_time_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained()->nullOnDelete();
            $table->date('order_placed_at');
            $table->date('order_received_at');
            $table->unsignedSmallInteger('days_actual'); // received - placed
            $table->string('source', 32)->default('synthetic'); // 'synthetic' | 'shopify' | 'csv' | 'manual'
            $table->timestamps();

            $table->index(['tenant_id', 'supplier_id', 'order_received_at'], 'lto_supplier_received_idx');
            $table->index(['tenant_id', 'sku_id', 'order_received_at'], 'lto_sku_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_time_observations');
    }
};
