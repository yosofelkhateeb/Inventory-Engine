<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('source', ['csv_upload', 'shopify', 'woocommerce', 'salla', 'manual']);
            $table->string('importer');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'partial'])->default('pending');
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_succeeded')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->json('error_log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_ingestion_runs');
    }
};
