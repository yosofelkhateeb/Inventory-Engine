<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('run_at');
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->unsignedInteger('decisions_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index('run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_runs');
    }
};
