<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->timestamp('run_at');
            $table->enum('decision', ['order', 'watch', 'hold', 'order_budget_blocked']);
            $table->unsignedInteger('recommended_qty')->default(0);
            $table->unsignedInteger('constrained_qty')->default(0);
            $table->json('reasoning');
            $table->decimal('forecast_demand', 8, 2)->default(0);
            $table->decimal('days_of_cover', 8, 2)->default(0);
            $table->decimal('reorder_point', 8, 2)->default(0);
            $table->timestamps();
            $table->index(['sku_id', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_decisions');
    }
};
