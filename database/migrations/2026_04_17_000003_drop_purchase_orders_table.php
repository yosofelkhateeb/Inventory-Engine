<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('purchase_orders');
    }

    public function down(): void
    {
        // purchase_orders has been superseded by inventory_decisions status lifecycle.
        // Restore only if rolling back the entire schema set in reverse order.
        Schema::create('purchase_orders', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordered_qty');
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->enum('status', ['recommended', 'approved', 'ordered', 'in_transit', 'received'])
                  ->default('recommended');
            $table->softDeletes();
            $table->timestamps();
        });
    }
};
