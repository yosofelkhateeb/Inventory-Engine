<?php

namespace App\Console\Commands;

use App\Jobs\RunDecisionCalibrationJob;
use Illuminate\Console\Command;

/**
 * Manual trigger for the decision-engine calibration loop.
 *
 * Usage:
 *   php artisan calibration:run                  # tenant 1, default F2 objective
 *   php artisan calibration:run --tenant=2 --objective=f1
 *   php artisan calibration:run --sync           # run inline (don't queue)
 *
 * Once Chunk 4 lands, the bi-weekly schedule dispatches the job
 * automatically — this command is for manual ops triggers and CI.
 */
class RunCalibrationCommand extends Command
{
    protected $signature = 'calibration:run
        {--tenant=1                   : Tenant ID to calibrate}
        {--objective=f2               : f1 | f2 | precision_at_recall}
        {--recall-floor=0.7           : Used only when objective = precision_at_recall}
        {--sync                       : Run synchronously instead of queuing}';

    protected $description = 'Calibrate decision-engine watch coefficients against historical snapshots';

    public function handle(): int
    {
        $tenantId    = (int)    $this->option('tenant');
        $objective   = (string) $this->option('objective');
        $recallFloor = (float)  $this->option('recall-floor');

        $job = new RunDecisionCalibrationJob($tenantId, $objective, $recallFloor);

        if ($this->option('sync')) {
            $this->info("Calibrating tenant {$tenantId} synchronously (objective={$objective})…");
            $started = microtime(true);
            $job->handle();
            $this->info('Done in '.round(microtime(true) - $started, 1).'s. Check the forecasting log channel for full results.');
        } else {
            dispatch($job);
            $this->info("Calibration dispatched to the 'forecasting' queue for tenant {$tenantId}.");
        }

        return self::SUCCESS;
    }
}
