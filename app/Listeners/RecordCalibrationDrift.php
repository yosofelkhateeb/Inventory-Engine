<?php

namespace App\Listeners;

use App\Events\DecisionCalibrationDriftDetected;
use App\Models\SystemAlert;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Persists a system_alerts row when calibration drift is detected.
 * Source of truth for ops history — email/Slack adapters subscribe
 * separately and must not lose the alert if delivery fails.
 *
 * Queued because it's a side-effect of a queued job; doesn't need to
 * complete synchronously with the calibration job's other work.
 */
class RecordCalibrationDrift implements ShouldQueue
{
    public string $queue = 'forecasting';

    public function handle(DecisionCalibrationDriftDetected $event): void
    {
        $title = sprintf(
            'Calibration drift: score regressed %.1f%% (%.4f → %.4f)',
            abs($event->deltaPct),
            $event->previousScore,
            $event->currentScore,
        );

        SystemAlert::withoutGlobalScopes()->create([
            'tenant_id' => $event->tenantId,
            'type'      => 'decision_calibration_drift',
            'severity'  => SystemAlert::SEVERITY_WARNING,
            'title'     => $title,
            'payload'   => [
                'objective'      => $event->objective,
                'previous_score' => $event->previousScore,
                'current_score'  => $event->currentScore,
                'delta_pct'      => $event->deltaPct,
                'threshold_pct'  => $event->thresholdPct,
                'recommendation' => 'Investigate dataset before trusting new coefficients.',
            ],
        ]);
    }
}
