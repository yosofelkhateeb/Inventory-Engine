<?php

namespace App\Services\InventoryEngine;

use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Models\SkuDemandProfile;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DemandForecaster
{
    private const LOOKBACK_DAYS = 90;

    public function forecast(int $skuId, int $horizonDays, int $tenantId = 1): ForecastResult
    {
        // ── Primary path: read demand_rate from forecast_model_registry ──────
        $registry = ForecastModelRegistry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sku_id', $skuId)
            ->first();

        if ($registry) {
            $dailyDemand = (float) $registry->demand_rate;

            // Compute stddev from recent sales for safety stock calculation
            $stddev = $this->computeStddev($skuId, $tenantId, $dailyDemand);

            // Pull trend from sku_demand_profiles — written by the Python
            // pipeline alongside the registry. Used by DecisionScorer's
            // watch threshold to widen/tighten when demand is moving.
            $profile = SkuDemandProfile::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sku_id', $skuId)
                ->first();

            return new ForecastResult(
                daily_demand:    round($dailyDemand, 4),
                demand_stddev:   round($stddev, 4),
                horizon_demand:  round($dailyDemand * $horizonDays, 4),
                horizon_days:    $horizonDays,
                method:          $registry->model_name,
                smape:           $registry->smape !== null ? (float) $registry->smape : null,
                trend_direction: $profile?->trend_direction,
            );
        }

        // ── Fallback: weighted moving average when no registry entry exists ──
        return $this->weightedMovingAverage($skuId, $horizonDays, $tenantId);
    }

    private function computeStddev(int $skuId, int $tenantId, float $mean): float
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        $qtys = SalesHistory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sku_id', $skuId)
            ->where('sale_date', '>=', $since)
            ->pluck('quantity_sold')
            ->map(fn ($q) => (float) $q);

        if ($qtys->count() < 2) {
            return 0.0;
        }

        return $this->stddev($qtys, $mean);
    }

    private function weightedMovingAverage(int $skuId, int $horizonDays, int $tenantId): ForecastResult
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        $records = SalesHistory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sku_id', $skuId)
            ->where('sale_date', '>=', $since)
            ->orderBy('sale_date')
            ->pluck('quantity_sold');

        if ($records->isEmpty()) {
            return new ForecastResult(
                daily_demand:   0.0,
                demand_stddev:  0.0,
                horizon_demand: 0.0,
                horizon_days:   $horizonDays,
                method:         'no_data',
            );
        }

        $qtys        = $records->map(fn ($q) => (float) $q);
        $avgDaily    = $qtys->average();
        $stddev      = $this->stddev($qtys, $avgDaily);

        return new ForecastResult(
            daily_demand:   round($avgDaily, 4),
            demand_stddev:  round($stddev, 4),
            horizon_demand: round($avgDaily * $horizonDays, 4),
            horizon_days:   $horizonDays,
            method:         'weighted_moving_average',
        );
    }

    private function stddev(Collection $qtys, float $mean): float
    {
        if ($qtys->count() < 2) {
            return 0.0;
        }

        $variance = $qtys->average(fn ($qty) => ($qty - $mean) ** 2);

        return sqrt($variance);
    }
}
