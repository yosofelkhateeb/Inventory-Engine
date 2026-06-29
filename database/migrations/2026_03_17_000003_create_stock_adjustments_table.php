<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('old_qty');
            $table->integer('new_qty');
            $table->integer('delta');
            $table->enum('reason_code', [
                'cycle_count',
                'damage_writeoff',
                'customer_return',
                'supplier_short_ship',
                'data_entry_correction',
                'internal_use',
            ]);
            $table->text('notes')->nullable();
            $table->timestamp('adjusted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
