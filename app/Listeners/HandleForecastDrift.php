<?php

namespace App\Listeners;

use App\Events\ForecastDriftDetected;
use App\Jobs\RunForecastJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class HandleForecastDrift implements ShouldQueue
{
    public string $queue = 'forecasting';

    public function handle(ForecastDriftDetected $event): void
    {
        Log::channel('forecasting')->info('Forecast drift detected — queuing re-run', [
            'sku_id'    => $event->skuId,
            'tenant_id' => $event->tenantId,
            'reason'    => $event->reason,
            'timestamp' => now()->toISOString(),
        ]);

        RunForecastJob::dispatch($event->skuId, $event->tenantId, 'bias_drift');
    }
}
