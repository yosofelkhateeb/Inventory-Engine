<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent record of every system-owner-facing alert. Distinct from
 * end-user notifications (StockAlertEvent, decision-status changes) —
 * these are about the *system itself* misbehaving:
 *
 *   - Calibration drift detected
 *   - Calibration job failed
 *   - Pipeline failures (future)
 *   - Stockout-after-hold incidents (future)
 *
 * Every alert lives here regardless of delivery channel. Email/Slack
 * adapters subscribe to the same event stream — failure of an email
 * relay must not lose the alert. The audit trail is the source of
 * truth; channels are a delivery mechanism on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('type', 64);          // e.g. 'decision_calibration_drift'
            $table->string('severity', 16);      // 'info' | 'warning' | 'critical'
            $table->string('title');             // human-readable summary
            $table->json('payload')->nullable(); // structured event data
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'severity', 'resolved_at'], 'sa_unresolved_idx');
            $table->index(['tenant_id', 'type', 'created_at'], 'sa_type_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
