<?php

namespace App\Jobs;

use App\Events\ForecastDriftDetected;
use App\Models\FeedbackMetric;
use App\Models\ForecastModelRegistry;
use App\Models\InventoryDecision;
use App\Models\SalesHistory;
use App\Models\SystemSetting;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeRecommendationFeedbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $periodEnd   = Carbon::today();
        $periodStart = $periodEnd->copy()->subDays(7);

        $decisions = InventoryDecision::withoutGlobalScopes()
            ->whereBetween('created_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->get(['id', 'tenant_id', 'sku_id', 'status', 'recommended_qty', 'ordered_qty', 'received_qty']);

        $grouped = $decisions->groupBy(fn ($d) => "{$d->tenant_id}:{$d->sku_id}");

        $driftCount = 0;

        foreach ($grouped as $key => $group) {
            [$tenantId, $skuId] = explode(':', $key);
            $tenantId = (int) $tenantId;
            $skuId    = (int) $skuId;

            TenantContext::run($tenantId, function () use ($group, $tenantId, $skuId, &$driftCount, $periodStart, $periodEnd) {
                $this->processGroup($group, $tenantId, $skuId, $driftCount, $periodStart, $periodEnd);
            });
        }

        $this->logPortfolioDigest($periodStart, $periodEnd, $decisions->count(), $grouped->count(), $driftCount);
    }

    private function processGroup($group, int $tenantId, int $skuId, int &$driftCount, Carbon $periodStart, Carbon $periodEnd): void
    {
            $total          = $group->count();
            $ignoredCount   = $group->where('status', InventoryDecision::STATUS_IGNORED)->count();
            $supersededCount = $group->where('status', InventoryDecision::STATUS_SUPERSEDED)->count();

            $ignoredRate    = $total > 0 ? $ignoredCount / $total : 0.0;
            $supersededRate = $total > 0 ? $supersededCount / $total : 0.0;

            $orderedGroup = $group->whereIn('status', [
                InventoryDecision::STATUS_ORDERED,
                InventoryDecision::STATUS_IN_TRANSIT,
                InventoryDecision::STATUS_RECEIVED,
            ])->filter(fn ($d) => $d->ordered_qty !== null && $d->recommended_qty !== null);

            $orderedDelta = $orderedGroup->count() > 0
                ? $orderedGroup->avg(fn ($d) => abs($d->recommended_qty - $d->ordered_qty))
                : 0.0;

            $receivedGroup = $group->where('status', InventoryDecision::STATUS_RECEIVED)
                ->filter(fn ($d) => $d->received_qty !== null && $d->ordered_qty !== null);

            $receivedDelta = $receivedGroup->count() > 0
                ? $receivedGroup->avg(fn ($d) => abs($d->ordered_qty - $d->received_qty))
                : 0.0;

            FeedbackMetric::withoutGlobalScopes()->create([
                'tenant_id'      => $tenantId,
                'sku_id'         => $skuId,
                'period_start'   => $periodStart->toDateString(),
                'period_end'     => $periodEnd->toDateString(),
                'ignored_rate'   => $ignoredRate,
                'ordered_delta'  => $orderedDelta,
                'received_delta' => $receivedDelta,
                'superseded_rate' => $supersededRate,
            ]);

            if ($this->checkThresholdsAndDispatch($tenantId, $skuId, $ignoredRate, $supersededRate, $orderedDelta, $receivedDelta)) {
                $driftCount++;
            }
    }

    /**
     * Emit a weekly portfolio-health snapshot to the forecasting log channel.
     * Gives ops a single grep target to spot silent drift week over week —
     * production WMAPE drifting back to 30%+ would show up here first.
     */
    private function logPortfolioDigest(
        Carbon $periodStart,
        Carbon $periodEnd,
        int $decisionsTotal,
        int $skusCovered,
        int $driftCount,
    ): void {
        $tenantIds = ForecastModelRegistry::withoutGlobalScopes()
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $registries = ForecastModelRegistry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->get(['sku_id', 'demand_rate']);

            if ($registries->isEmpty()) {
                continue;
            }

            $since       = Carbon::today()->subDays(30);
            $totalActual = 0.0;
            $totalError  = 0.0;

            foreach ($registries as $reg) {
                $actualAvg = (float) (SalesHistory::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('sku_id', $reg->sku_id)
                    ->where('sale_date', '>=', $since)
                    ->avg('quantity_sold') ?? 0.0);

                $totalActual += abs($actualAvg);
                $totalError  += abs((float) $reg->demand_rate - $actualAvg);
            }

            $wmape = $totalActual > 0 ? round(($totalError / $totalActual) * 100, 2) : null;

            Log::channel('forecasting')->info('weekly_portfolio_digest', [
                'tenant_id'            => $tenantId,
                'period_start'         => $periodStart->toDateString(),
                'period_end'           => $periodEnd->toDateString(),
                'sku_count'            => $registries->count(),
                'portfolio_wmape_pct'  => $wmape,
                'decisions_in_period'  => $decisionsTotal,
                'skus_with_decisions'  => $skusCovered,
                'drift_events'         => $driftCount,
            ]);
        }
    }

    private function checkThresholdsAndDispatch(
        int $tenantId,
        int $skuId,
        float $ignoredRate,
        float $supersededRate,
        float $orderedDelta,
        float $receivedDelta,
    ): bool {
        $ignoredThreshold    = (float) SystemSetting::get($tenantId, 'feedback.ignored_rate_threshold', 0.30);
        $supersededThreshold = (float) SystemSetting::get($tenantId, 'feedback.superseded_rate_threshold', 0.50);
        $orderedThreshold    = (float) SystemSetting::get($tenantId, 'feedback.ordered_delta_threshold', 5.0);
        $receivedThreshold   = (float) SystemSetting::get($tenantId, 'feedback.received_delta_threshold', 3.0);

        $reason = match (true) {
            $ignoredRate > $ignoredThreshold       => "ignored_rate {$ignoredRate} exceeds threshold {$ignoredThreshold}",
            $supersededRate > $supersededThreshold => "superseded_rate {$supersededRate} exceeds threshold {$supersededThreshold}",
            $orderedDelta > $orderedThreshold      => "ordered_delta {$orderedDelta} exceeds threshold {$orderedThreshold}",
            $receivedDelta > $receivedThreshold    => "received_delta {$receivedDelta} exceeds threshold {$receivedThreshold}",
            default                                => null,
        };

        if ($reason !== null) {
            ForecastDriftDetected::dispatch($skuId, $tenantId, $reason);

            return true;
        }

        return false;
    }
}
