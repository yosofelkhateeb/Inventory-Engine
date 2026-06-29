<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regional_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 5)->default('SA');
            $table->string('holiday_name');
            $table->date('holiday_date');
            $table->unsignedSmallInteger('year');
            $table->decimal('default_uplift_pct', 6, 2)->default(0);
            $table->timestamps();

            $table->index(['country_code', 'year']);
            $table->unique(['country_code', 'holiday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_holidays');
    }
};
