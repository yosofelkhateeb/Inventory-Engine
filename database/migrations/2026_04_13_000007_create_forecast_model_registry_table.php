<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_model_registry', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->unsignedBigInteger('sku_id');
            $table->string('model_name', 64);
            $table->decimal('demand_rate', 10, 4);
            $table->unsignedSmallInteger('forecast_horizon_days')->default(30);
            $table->decimal('mae', 10, 4)->nullable();
            $table->decimal('rmse', 10, 4)->nullable();
            $table->decimal('bias', 10, 4)->nullable();
            $table->decimal('smape', 8, 2)->nullable();
            $table->string('reeval_trigger', 32)->default('scheduled');
            $table->json('warnings')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sku_id')->references('id')->on('skus')->cascadeOnDelete();

            // One active registry entry per SKU per tenant
            $table->unique(['tenant_id', 'sku_id']);
            $table->index(['tenant_id', 'next_review_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_model_registry');
    }
};
