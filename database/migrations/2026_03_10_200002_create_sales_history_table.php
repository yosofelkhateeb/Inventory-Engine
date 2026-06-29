<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->date('sale_date');
            $table->unsignedInteger('quantity_sold');
            $table->timestamps();
            $table->index(['sku_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_history');
    }
};
