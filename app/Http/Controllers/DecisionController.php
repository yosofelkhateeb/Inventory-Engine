<?php

namespace App\Http\Controllers;

use App\Models\ForecastModelRegistry;
use App\Models\InventoryDecision;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DecisionController extends Controller
{
    public function index(Request $request): Response
    {
        // Portable lifecycle ordering (MySQL's FIELD() isn't available on SQLite
        // used in local dev / tests). Kept in sync with InventoryDecision::STATUSES.
        $statusOrder = 'CASE status';
        foreach (InventoryDecision::STATUSES as $i => $status) {
            $statusOrder .= " WHEN '{$status}' THEN {$i}";
        }
        $statusOrder .= ' ELSE 99 END';

        $query = InventoryDecision::with(['sku.supplier'])
            ->orderByRaw($statusOrder)
            ->orderBy('updated_at', 'desc');

        if ($request->filled('decision')) {
            $query->where('decision', $request->decision);
        }

        // Tab-driven filter. The Recommendations page splits into three tabs:
        //   pending    — status = pending (default; "to triage")
        //   in_flight  — status IN (acknowledged, ordered, in_transit) ("to track")
        //   audit      — separate route /decisions/audit (handled by auditLog method)
        //
        // SKU deeplink (?sku=<code>) is the one override: when set, it short-
        // circuits the tab filter so the operator's Act link from the Dashboard
        // lands on the row regardless of which tab the SKU's current status
        // would otherwise put it in.
        $tab = $request->input('tab', 'pending');

        if ($request->filled('sku')) {
            $query->whereHas('sku', fn ($q) => $q->where('sku_code', $request->sku));
        } elseif ($tab === 'in_flight') {
            $query->whereIn('status', [
                InventoryDecision::STATUS_ACKNOWLEDGED,
                InventoryDecision::STATUS_ORDERED,
                InventoryDecision::STATUS_IN_TRANSIT,
            ]);
        } else {
            $tab = 'pending';
            $query->where('status', InventoryDecision::STATUS_PENDING);
        }

        $paginated = $query->paginate(25)->withQueryString();

        // Build confidence map from forecast registry (smape-based)
        $skuIds      = $paginated->pluck('sku_id')->unique()->toArray();
        $registries  = ForecastModelRegistry::whereIn('sku_id', $skuIds)
            ->get(['sku_id', 'smape'])
            ->keyBy('sku_id');

        $decisions = $paginated->through(fn ($d) => [
            'id'              => $d->id,
            'sku_id'          => $d->sku_id,
            'sku_code'        => $d->sku->sku_code,
            'sku_name'        => $d->sku->name,
            'supplier_name'   => $d->sku->supplier?->name,
            'decision'        => $d->decision,
            'status'          => $d->status,
            'constrained_qty' => $d->constrained_qty,
            'recommended_qty' => $d->recommended_qty,
            'ordered_qty'     => $d->ordered_qty,
            'received_qty'    => $d->received_qty,
            'days_of_cover'   => (float) $d->days_of_cover,
            'forecast_demand' => (float) $d->forecast_demand,
            'reorder_point'   => (float) $d->reorder_point,
            'lead_time_days'  => $d->sku->lead_time_days,
            'confidence'      => $this->confidenceLabel($registries->get($d->sku_id)?->smape),
            'updated_at'      => $d->updated_at?->toISOString(),
            'run_at'          => $d->run_at?->toISOString(),
        ]);

        return Inertia::render('Decisions/Index', [
            'decisions'     => $decisions,
            'filters'       => $request->only(['decision', 'sku']),
            'activeTab'     => $tab, // 'pending' or 'in_flight'
            'statuses'      => InventoryDecision::STATUSES,
            'decisionTypes' => ['order', 'watch', 'hold', 'order_budget_blocked'],
        ]);
    }

    public function auditLog(Request $request): Response
    {
        $query = InventoryDecision::with('sku:id,name,sku_code')
            ->whereNotNull('status_history')
            ->orderBy('updated_at', 'desc');

        if ($request->filled('sku')) {
            $query->whereHas('sku', fn ($q) => $q->where('sku_code', $request->sku));
        }

        $log = $query->paginate(50)->withQueryString();

        $log->through(fn ($d) => [
            'id'             => $d->id,
            'sku_id'         => $d->sku_id,
            'sku_code'       => $d->sku->sku_code,
            'sku_name'       => $d->sku->name,
            'status_history' => $d->status_history ?? [],
            'ignored_reason' => $d->ignored_reason,
            'updated_at'     => $d->updated_at?->toISOString(),
        ]);

        return Inertia::render('Decisions/Index', [
            'decisions'     => null,
            'log'           => $log,
            'filters'       => $request->only(['sku']),
            'statuses'      => InventoryDecision::STATUSES,
            'decisionTypes' => ['order', 'watch', 'hold', 'order_budget_blocked'],
            'activeTab'     => 'audit',
        ]);
    }

    private function confidenceLabel(?float $smape): string|null
    {
        if ($smape === null) {
            return null;
        }
        if ($smape <= 20) {
            return 'high';
        }
        if ($smape <= 40) {
            return 'medium';
        }
        return 'low';
    }
}
