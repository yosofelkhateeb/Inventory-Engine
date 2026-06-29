<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku_code')->unique();
            $table->unsignedInteger('moq')->default(1);
            $table->unsignedBigInteger('unit_cost'); // stored in halalas (× 100)
            $table->unsignedInteger('reorder_qty')->default(0);
            $table->unsignedInteger('current_stock')->default(0);
            $table->unsignedInteger('in_transit_qty')->default(0);
            $table->unsignedInteger('reserved_qty')->default(0);
            $table->unsignedInteger('lead_time_days')->default(7);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skus');
    }
};
