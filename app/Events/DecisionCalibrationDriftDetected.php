<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by RunDecisionCalibrationJob when a calibration run produces a
 * score that regresses past the configured drift threshold. Indicates
 * that something changed structurally in the data — a connector outage
 * distorting recent snapshots, a demand-pattern shift, or a model bug.
 *
 * System-owner concern, not end-user. Listeners route it to
 * SystemAlert + email / Slack / etc.
 */
class DecisionCalibrationDriftDetected
{
    use Dispatchable;

    public function __construct(
        public readonly int    $tenantId,
        public readonly string $objective,
        public readonly float  $previousScore,
        public readonly float  $currentScore,
        public readonly float  $deltaPct,
        public readonly float  $thresholdPct,
    ) {}
}
