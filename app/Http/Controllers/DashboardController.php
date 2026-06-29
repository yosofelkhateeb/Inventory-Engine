<?php

namespace App\Http\Controllers;

use App\Models\EngineRun;
use App\Models\ForecastModelRegistry;
use App\Models\InventoryDecision;
use App\Models\SalesHistory;
use App\Models\Sku;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $latestRun = InventoryDecision::max('run_at');

        $decisions = InventoryDecision::where('run_at', $latestRun)
            ->with('sku')
            ->get();

        $mapped = $decisions->map(fn ($d) => [
            'id'                 => $d->id,
            'sku_id'             => $d->sku_id,
            'sku_code'           => $d->sku->sku_code,
            'sku_name'           => $d->sku->name,
            'decision'           => $d->decision,
            'status'             => $d->status,
            'constrained_qty'    => $d->constrained_qty,
            'days_of_cover'      => (float) $d->days_of_cover,
            'reorder_point'      => $d->reorder_point,
            'forecast_demand'    => $d->forecast_demand,
            'safety_stock'       => $d->reasoning['safety_stock'] ?? 0,
            'current_stock'      => $d->sku->current_stock,
            'in_transit_qty'     => $d->sku->in_transit_qty,
            'reserved_qty'       => $d->sku->reserved_qty,
            'effective_position' => $d->sku->effective_position,
            'lead_time_days'     => $d->sku->lead_time_days,
            'abc_class'          => $d->sku->abc_class,
            'xyz_class'          => $d->sku->xyz_class,
            'run_at'             => $d->run_at,
        ]);

        // Dashboard is the operator's "needs attention" surface. Once the
        // operator takes any action (acknowledged, ordered, in_transit,
        // received, ignored, superseded), the row drops off until either the
        // operator walks it back to pending or the engine creates a new
        // recommendation. Single rule: status === 'pending' ⇔ on Dashboard.
        // Actions live on the Recommendations page.
        $active = $mapped->where('status', 'pending');

        $sorted = $active->sortBy([
            fn ($a, $b) => $this->urgencyPriority($a) <=> $this->urgencyPriority($b),
            fn ($a, $b) => $a['days_of_cover'] <=> $b['days_of_cover'],
        ])->values();

        // Three exclusive tiers — counts sum to the active recommendation count, no overlap.
        // Order Now = urgent (order/budget_blocked AND cover < lead time)
        // Order Soon = planned (order/budget_blocked AND cover >= lead time)
        // Watchlist  = monitor (watch decisions)
        $orderDecisions = ['order', 'order_budget_blocked'];

        $orderNowCount = $active->filter(fn ($d) =>
            in_array($d['decision'], $orderDecisions, true)
            && $d['days_of_cover'] < $d['lead_time_days']
        )->count();

        $orderSoonCount = $active->filter(fn ($d) =>
            in_array($d['decision'], $orderDecisions, true)
            && $d['days_of_cover'] >= $d['lead_time_days']
        )->count();

        $watchlistCount = $active->where('decision', 'watch')->count();

        $lastRun = EngineRun::where('status', '!=', 'running')
            ->latest('run_at')
            ->first(['run_at', 'decisions_count', 'status']);

        $deadStock = $this->getDeadStockSkus();

        $recentActivity = InventoryDecision::with('sku:id,name,sku_code')
            ->where('status', '!=', 'pending')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($d) => [
                'id'         => $d->id,
                'sku_name'   => $d->sku->name,
                'sku_code'   => $d->sku->sku_code,
                'status'     => $d->status,
                'updated_at' => $d->updated_at->toISOString(),
            ]);

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'order_now'      => $orderNowCount,
                'order_soon'     => $orderSoonCount,
                'watchlist'      => $watchlistCount,
                'avg_days_cover' => round($mapped->avg('days_of_cover') ?? 0.0, 1),
                'wmape'          => $this->computeWmape(),
            ],
            'decisions'      => $sorted,
            'lastRun'        => $lastRun,
            'deadStock'      => $deadStock,
            'recentActivity' => $recentActivity,
        ]);
    }

    private function getDeadStockSkus(): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        $recentlySoldIds = SalesHistory::where('sale_date', '>=', $thirtyDaysAgo)
            ->distinct()
            ->pluck('sku_id');

        return Sku::where('current_stock', '>', 0)
            ->whereNotIn('id', $recentlySoldIds)
            ->get(['id', 'name', 'sku_code', 'current_stock'])
            ->toArray();
    }

    /**
     * Portfolio-level Weighted MAPE over the trailing 30 days.
     * WMAPE = sum(|forecast - actual|) / sum(|actual|) × 100
     *
     * Uses the latest forecast_demand from forecast_model_registry per SKU
     * compared to average daily actual sales over the trailing 30 days.
     * Returns null when no registry entries exist yet.
     */
    private function computeWmape(): ?float
    {
        $since = Carbon::today()->subDays(30);

        $registries = ForecastModelRegistry::select('sku_id', 'demand_rate')->get();

        if ($registries->isEmpty()) {
            return null;
        }

        $totalActual = 0.0;
        $totalError  = 0.0;

        foreach ($registries as $reg) {
            $actualAvg = SalesHistory::withoutGlobalScopes()
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

    private function urgencyPriority(array $d): int
    {
        return match (true) {
            $d['decision'] === 'order' && $d['days_of_cover'] < $d['lead_time_days'] => 0,
            $d['decision'] === 'order'                                                => 1,
            $d['decision'] === 'order_budget_blocked'                                 => 2,
            $d['decision'] === 'watch'                                                => 3,
            default                                                                   => 4,
        };
    }
}
