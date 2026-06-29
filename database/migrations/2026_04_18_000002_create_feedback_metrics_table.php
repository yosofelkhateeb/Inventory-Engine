<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->float('ignored_rate')->default(0);
            $table->float('ordered_delta')->default(0);
            $table->float('received_delta')->default(0);
            $table->float('superseded_rate')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'sku_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_metrics');
    }
};
