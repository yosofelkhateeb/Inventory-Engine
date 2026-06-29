<?php

namespace App\Jobs;

use App\Events\DecisionCalibrationDriftDetected;
use App\Models\SystemSetting;
use App\Services\Training\CalibrationGridSearch;
use App\Services\Training\CalibrationOutcomeAnalyzer;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the decision-engine calibration loop for one tenant: grid-searches
 * the (k_lead, k_ltv, k_smape, k_trend) space against the historical
 * snapshots + ground truth tables, writes the optimum to system_settings,
 * and logs a summary to the `forecasting` channel.
 *
 * Triggered by:
 * - The bi-weekly schedule (Chunk 4)
 * - Manual artisan command `calibration:run --tenant=N` (this PR)
 * - Future: dataset drift detection
 *
 * Idempotent: each invocation overwrites the four setting keys with the
 * latest optimum. Concurrent runs are guarded by a queue lock (1 per
 * tenant) so a manual trigger during a scheduled run can't corrupt state.
 *
 * Heavy work (~10 min on a 30-SKU × 3-year dataset). Queued; not for
 * synchronous request handling.
 */
class RunDecisionCalibrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min ceiling
    public int $tries   = 1;

    public function __construct(
        public readonly int     $tenantId   = 1,
        public readonly string  $objective  = 'f2',
        public readonly float   $recallFloor = 0.7,
    ) {
        $this->onQueue('forecasting');
    }

    public function uniqueId(): string
    {
        return "decision-calibration-tenant-{$this->tenantId}";
    }

    public function handle(): void
    {
        TenantContext::run($this->tenantId, fn () => $this->runForTenant());
    }

    private function runForTenant(): void
    {
        $started = microtime(true);

        $analyzer = new CalibrationOutcomeAnalyzer();
        $search   = new CalibrationGridSearch($analyzer);

        Log::channel('forecasting')->info('decision_calibration_started', [
            'tenant_id'    => $this->tenantId,
            'objective'    => $this->objective,
            'recall_floor' => $this->recallFloor,
        ]);

        $result = $search->search(
            tenantId:    $this->tenantId,
            objective:   $this->objective,
            recallFloor: $this->recallFloor,
        );

        if ($result['best'] === null) {
            Log::channel('forecasting')->warning('decision_calibration_no_optimum', [
                'tenant_id' => $this->tenantId,
                'reason'    => 'grid search returned no candidate (likely empty training set)',
            ]);
            return;
        }

        $best = $result['best'];

        // Capture pre-write values so the log shows the delta
        $previous = [
            'k_lead'  => (float) SystemSetting::get($this->tenantId, 'decision.watch.k_lead',  0.5),
            'k_ltv'   => (float) SystemSetting::get($this->tenantId, 'decision.watch.k_ltv',   1.65),
            'k_smape' => (float) SystemSetting::get($this->tenantId, 'decision.watch.k_smape', 0.5),
            'k_trend' => (float) SystemSetting::get($this->tenantId, 'decision.watch.k_trend', 0.0),
        ];
        $previousScoreRaw = SystemSetting::get($this->tenantId, 'decision.watch.calibration_score', null);
        $previousScore    = $previousScoreRaw !== null ? (float) $previousScoreRaw : null;

        // ── Drift detection ──────────────────────────────────────────────
        // If the new optimum scores meaningfully lower than the previous run,
        // something changed structurally in the data — a connector outage
        // distorting recent snapshots, a demand-pattern shift, or a bug.
        // Emit a separate log event so monitoring can subscribe and alert.
        $driftThreshold = (float) SystemSetting::get(
            $this->tenantId,
            'decision.watch.calibration_drift_threshold_pct',
            20.0,
        ) / 100;
        if ($previousScore !== null && $previousScore > 0) {
            $delta    = ($best['score'] - $previousScore) / $previousScore;  // signed
            if ($delta < -$driftThreshold) {
                Log::channel('forecasting')->warning('decision_calibration_drift_detected', [
                    'tenant_id'        => $this->tenantId,
                    'objective'        => $this->objective,
                    'previous_score'   => round($previousScore, 4),
                    'current_score'    => round($best['score'], 4),
                    'delta_pct'        => round($delta * 100, 1),
                    'threshold_pct'    => round($driftThreshold * 100, 1),
                    'recommendation'   => 'Investigate dataset before trusting new coefficients.',
                ]);

                // Dispatch the event so listeners (record + email) can
                // route the alert. The log entry above is preserved for
                // operators tailing the file directly; the event is the
                // structured path for SystemAlert + email.
                DecisionCalibrationDriftDetected::dispatch(
                    tenantId:      $this->tenantId,
                    objective:     $this->objective,
                    previousScore: round($previousScore, 4),
                    currentScore:  round($best['score'], 4),
                    deltaPct:      round($delta * 100, 1),
                    thresholdPct:  round($driftThreshold * 100, 1),
                );
            }
        }

        $this->writeSetting('decision.watch.k_lead',  (string) $best['k_lead']);
        $this->writeSetting('decision.watch.k_ltv',   (string) $best['k_ltv']);
        $this->writeSetting('decision.watch.k_smape', (string) $best['k_smape']);
        $this->writeSetting('decision.watch.k_trend', (string) $best['k_trend']);

        // Audit trail of when calibration last ran + what it produced
        $this->writeSetting('decision.watch.calibrated_at', now()->toIso8601String());
        $this->writeSetting('decision.watch.calibration_score', (string) round($best['score'], 4));
        $this->writeSetting('decision.watch.calibration_objective', $this->objective);

        Log::channel('forecasting')->info('decision_calibration_completed', [
            'tenant_id'   => $this->tenantId,
            'objective'   => $this->objective,
            'previous'    => $previous,
            'optimum'     => [
                'k_lead'  => $best['k_lead'],
                'k_ltv'   => $best['k_ltv'],
                'k_smape' => $best['k_smape'],
                'k_trend' => $best['k_trend'],
            ],
            'metrics'     => $best['metrics'],
            'evaluations' => $result['evaluations'],
            'elapsed_sec' => round(microtime(true) - $started, 2),
        ]);
    }

    private function writeSetting(string $key, string $value): void
    {
        SystemSetting::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'key' => $key],
            ['value' => $value, 'group' => 'decision_calibration'],
        );
    }
}
