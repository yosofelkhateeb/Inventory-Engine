<?php

namespace App\Listeners;

use App\Events\DecisionCalibrationDriftDetected;
use App\Mail\SystemAlertMail;
use App\Models\SystemAlert;
use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an email to the system-owner address when calibration drift fires.
 * No-ops gracefully when no recipient is configured — useful in dev where
 * we don't want emails leaving the box.
 *
 * Reads the most recent system_alerts row for this tenant + drift type
 * and uses it as the email payload, so the email content matches the
 * persisted record exactly. RecordCalibrationDrift listener runs first
 * (registered first in EventServiceProvider) and creates that row.
 *
 * The recipient address is per-tenant via system_settings:
 *   `notifications.system_owner_email` — string, nullable. When null,
 *    the listener no-ops. Configurable in the Settings UI later.
 */
class EmailSystemOwnerOnDrift implements ShouldQueue
{
    public string $queue = 'forecasting';

    public function handle(DecisionCalibrationDriftDetected $event): void
    {
        $recipient = SystemSetting::get(
            $event->tenantId,
            'notifications.system_owner_email',
            null,
        );

        if (empty($recipient)) {
            Log::channel('forecasting')->info('drift_email_skipped_no_recipient', [
                'tenant_id' => $event->tenantId,
            ]);
            return;
        }

        $alert = SystemAlert::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('type',      'decision_calibration_drift')
            ->latest('created_at')
            ->first();

        if ($alert === null) {
            // Race: somehow the RecordCalibrationDrift listener hasn't
            // persisted yet (different queues, retry conditions, etc.).
            // Log loudly — losing the email is preferable to sending
            // one that doesn't match the audit record.
            Log::channel('forecasting')->warning('drift_email_skipped_no_alert_row', [
                'tenant_id' => $event->tenantId,
            ]);
            return;
        }

        Mail::to($recipient)->queue(new SystemAlertMail($alert));
    }
}
