<?php

namespace App\Http\Controllers;

use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __invoke(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $since    = Carbon::today()->subDays(30);

        $registries = ForecastModelRegistry::with('sku')
            ->where('tenant_id', $tenantId)
            ->orderBy('sku_id')
            ->get();

        $wmape      = $this->computeWmape($registries, $tenantId, $since);
        $staleSkus  = $this->findStaleSkus($registries, $tenantId);

        $rows = $registries->map(fn ($r) => [
            'sku_id'                      => $r->sku_id,
            'sku_code'                    => $r->sku->sku_code,
            'sku_name'                    => $r->sku->name,
            'model_name'                  => $r->model_name,
            'demand_rate'                 => (float) $r->demand_rate,
            'mae'                         => $r->mae !== null ? (float) $r->mae : null,
            'smape'                       => $r->smape !== null ? (float) $r->smape : null,
            'interval_lower'              => $r->interval_lower !== null ? (float) $r->interval_lower : null,
            'interval_upper'              => $r->interval_upper !== null ? (float) $r->interval_upper : null,
            'interval_confidence'         => $r->interval_confidence !== null ? (float) $r->interval_confidence : null,
            'interval_empirical_coverage' => $r->interval_empirical_coverage !== null ? (float) $r->interval_empirical_coverage : null,
            'selection_rationale'         => $r->selection_rationale,
            'reeval_trigger'              => $r->reeval_trigger,
            'warnings'                    => $r->warnings ?? [],
            'trained_at'                  => $r->trained_at?->toDateTimeString(),
            'next_review_at'              => $r->next_review_at?->toDateTimeString(),
        ])->values();

        return Inertia::render('Reports/Index', [
            'rows'       => $rows,
            'wmape'      => $wmape,
            'stale_skus' => $staleSkus,
        ]);
    }

    /**
     * Find SKUs whose last sale is older than the configured staleness threshold.
     * Surfaced as a banner on the Reports page so users notice silent feed rot
     * before it corrupts forecasts.
     */
    private function findStaleSkus($registries, int $tenantId): array
    {
        if ($registries->isEmpty()) {
            return [];
        }

        $thresholdDays = (int) SystemSetting::get($tenantId, 'monitoring.sku_staleness_warning_days', 7);
        $today         = Carbon::today();
        $skuIds        = $registries->pluck('sku_id')->all();

        $latestSales = SalesHistory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('sku_id', $skuIds)
            ->selectRaw('sku_id, MAX(sale_date) as last_sale_date')
            ->groupBy('sku_id')
            ->pluck('last_sale_date', 'sku_id');

        $stale = [];
        foreach ($registries as $reg) {
            $last = $latestSales[$reg->sku_id] ?? null;

            if ($last === null) {
                continue;
            }

            $staleness = $today->diffInDays(Carbon::parse($last), false);
            $staleness = abs((int) $staleness);

            if ($staleness > $thresholdDays) {
                $stale[] = [
                    'sku_id'         => $reg->sku_id,
                    'sku_code'       => $reg->sku->sku_code,
                    'sku_name'       => $reg->sku->name,
                    'staleness_days' => $staleness,
                    'last_sale_date' => Carbon::parse($last)->toDateString(),
                ];
            }
        }

        usort($stale, fn ($a, $b) => $b['staleness_days'] <=> $a['staleness_days']);

        return $stale;
    }

    private function computeWmape($registries, int $tenantId, Carbon $since): ?float
    {
        if ($registries->isEmpty()) {
            return null;
        }

        $totalActual = 0.0;
        $totalError  = 0.0;

        foreach ($registries as $reg) {
            $actualAvg = SalesHistory::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sku_id', $reg->sku_id)
                ->where('sale_date', '>=', $since)
                ->avg('quantity_sold') ?? 0.0;

            $totalActual += abs($actualAvg);
            $totalError  += abs((float) $reg->demand_rate - $actualAvg);
        }

        if ($totalActual <= 0) {
            return null;
        }

        return round(($totalError / $totalActual) * 100, 1);
    }
}
