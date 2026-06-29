<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_decisions', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'acknowledged',
                'ordered',
                'in_transit',
                'received',
                'ignored',
                'superseded',
            ])->default('pending')->after('reasoning');

            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete()->after('status_changed_at');
            $table->text('status_notes')->nullable()->after('status_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['status', 'status_changed_at', 'status_notes']);
        });
    }
};
