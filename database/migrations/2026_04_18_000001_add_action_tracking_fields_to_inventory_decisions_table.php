<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_decisions', function (Blueprint $table) {
            $table->json('status_history')->nullable()->after('status_notes');
            $table->unsignedInteger('ordered_qty')->nullable()->after('status_history');
            $table->timestamp('ordered_at')->nullable()->after('ordered_qty');
            $table->date('expected_arrival')->nullable()->after('ordered_at');
            $table->foreignId('supplier_id')->nullable()->after('expected_arrival')
                ->constrained('suppliers')->nullOnDelete();
            $table->unsignedInteger('received_qty')->nullable()->after('supplier_id');
            $table->timestamp('received_at')->nullable()->after('received_qty');
            $table->string('ignored_reason')->nullable()->after('received_at');
            $table->foreignId('superseded_by_decision_id')->nullable()->after('ignored_reason')
                ->references('id')->on('inventory_decisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_decisions', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['superseded_by_decision_id']);
            $table->dropColumn([
                'status_history',
                'ordered_qty',
                'ordered_at',
                'expected_arrival',
                'supplier_id',
                'received_qty',
                'received_at',
                'ignored_reason',
                'superseded_by_decision_id',
            ]);
        });
    }
};
