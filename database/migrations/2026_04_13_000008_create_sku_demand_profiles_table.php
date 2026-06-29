<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_demand_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->unsignedBigInteger('sku_id');
            $table->string('volume_tier', 16);        // low | medium | high
            $table->string('volatility', 16);         // stable | moderate | erratic
            $table->string('intermittency', 16);      // continuous | intermittent
            $table->boolean('seasonality_detected')->default(false);
            $table->string('trend_direction', 16);    // upward | flat | declining
            $table->unsignedSmallInteger('history_days_used')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sku_id')->references('id')->on('skus')->cascadeOnDelete();

            $table->unique(['tenant_id', 'sku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_demand_profiles');
    }
};
