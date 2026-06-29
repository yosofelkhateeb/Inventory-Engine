<?php

namespace App\Jobs;

use App\Models\InventoryDecision;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\SystemSetting;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compare each SKU's latest forecast_demand against the trailing 30-day sales mean.
 * If the absolute bias exceeds `forecast.bias_drift_threshold_pct` (default 15%),
 * dispatch RunForecastJob with the `bias_drift` trigger so the pipeline re-evaluates.
 *
 * This used to run inline at the tail of InventoryEngineService::run(), but on
 * QUEUE_CONNECTION=sync that cascaded into a synchronous Python subprocess inside
 * the web request and blew past PHP's 30s execution limit. The engine run is now
 * a pure scoring pass; drift detection runs on a daily schedule instead (see
 * routes/console.php).
 */
class CheckBiasDriftJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $since = Carbon::today()->subDays(30);

        $skus = Sku::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->get(['id', 'tenant_id']);

        $tenantIds = $skus->pluck('tenant_id')->unique();

        foreach ($tenantIds as $tenantId) {
            TenantContext::run($tenantId, function () use ($tenantId, $skus, $since) {
            $threshold = (float) SystemSetting::get(
                $tenantId,
                'forecast.bias_drift_threshold_pct',
                15.0,
            );

            $tenantSkus = $skus->where('tenant_id', $tenantId);

            foreach ($tenantSkus as $sku) {
                $latestDecision = InventoryDecision::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('sku_id', $sku->id)
                    ->orderByDesc('run_at')
                    ->first(['forecast_demand']);

                if (! $latestDecision) {
                    continue;
                }

                $actualAvg = SalesHistory::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('sku_id', $sku->id)
                    ->where('sale_date', '>=', $since)
                    ->avg('quantity_sold');

                if (! $actualAvg || $actualAvg <= 0) {
                    continue;
                }

                $biasPct = (($latestDecision->forecast_demand - $actualAvg) / $actualAvg) * 100;

                if (abs($biasPct) > $threshold) {
                    RunForecastJob::dispatch($sku->id, $tenantId, 'bias_drift');
                }
            }
            });
        }
    }
}
