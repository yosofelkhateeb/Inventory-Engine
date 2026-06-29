<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
