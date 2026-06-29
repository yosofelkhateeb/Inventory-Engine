<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSkuRequest;
use App\Models\FeedbackMetric;
use App\Models\ForecastModelRegistry;
use App\Models\Sku;
use App\Models\SkuDemandProfile;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SkuController extends Controller
{
    public function index()
    {
        $skus = Sku::with(['supplier', 'decisions' => fn ($q) => $q->latest('run_at')->limit(1)])
            ->get()
            ->map(fn ($sku) => [
                'id'                 => $sku->id,
                'sku_code'           => $sku->sku_code,
                'name'               => $sku->name,
                'category'           => $sku->category,
                'current_stock'      => $sku->current_stock,
                'effective_position' => $sku->effective_position,
                'unit_cost_sar'      => $sku->unit_cost_sar,
                'moq'                => $sku->moq,
                'lead_time_days'     => $sku->lead_time_days,
                'supplier_id'        => $sku->supplier_id,
                'supplier_name'      => $sku->supplier->name,
                'latest_decision'    => $sku->decisions->first()?->decision,
                'days_of_cover'      => $sku->decisions->first()?->days_of_cover,
                'abc_class'          => $sku->abc_class,
                'xyz_class'          => $sku->xyz_class,
                'safety_stock_multiplier_override' => $sku->safety_stock_multiplier_override !== null
                    ? (float) $sku->safety_stock_multiplier_override
                    : null,
            ]);

        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return Inertia::render('SKUs/Index', compact('skus', 'suppliers'));
    }

    public function show(Sku $sku)
    {
        $sku->load('supplier');

        $latestDecision = $sku->decisions()->latest('run_at')->first();

        $decisionHistory = $sku->decisions()
            ->latest('run_at')
            ->limit(10)
            ->get()
            ->map(fn ($d) => [
                'id'             => $d->id,
                'decision'       => $d->decision,
                'status'         => $d->status,
                'constrained_qty'=> $d->constrained_qty,
                'recommended_qty'=> $d->recommended_qty,
                'ordered_qty'    => $d->ordered_qty,
                'received_qty'   => $d->received_qty,
                'ignored_reason' => $d->ignored_reason,
                'days_of_cover'  => (float) $d->days_of_cover,
                'run_at'         => $d->run_at?->toDateString(),
                'created_at'     => $d->created_at?->toDateString(),
            ]);

        $registry = ForecastModelRegistry::withoutGlobalScopes()
            ->where('tenant_id', $sku->tenant_id)
            ->where('sku_id', $sku->id)
            ->first();

        $demandProfile = SkuDemandProfile::withoutGlobalScopes()
            ->where('tenant_id', $sku->tenant_id)
            ->where('sku_id', $sku->id)
            ->first();

        $feedbackMetrics = FeedbackMetric::withoutGlobalScopes()
            ->where('tenant_id', $sku->tenant_id)
            ->where('sku_id', $sku->id)
            ->orderBy('period_start', 'desc')
            ->limit(4)
            ->get()
            ->map(fn ($m) => [
                'period_start'    => $m->period_start?->toDateString(),
                'period_end'      => $m->period_end?->toDateString(),
                'ignored_rate'    => $m->ignored_rate,
                'ordered_delta'   => $m->ordered_delta,
                'received_delta'  => $m->received_delta,
                'superseded_rate' => $m->superseded_rate,
            ]);

        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return Inertia::render('SKUs/Show', [
            'sku'            => array_merge($sku->toArray(), [
                'unit_cost_sar' => $sku->unit_cost_sar,
                'effective_position' => $sku->effective_position,
                'safety_stock_multiplier_override' => $sku->safety_stock_multiplier_override !== null
                    ? (float) $sku->safety_stock_multiplier_override
                    : null,
            ]),
            'suppliers'      => $suppliers,
            'latestDecision' => $latestDecision ? [
                'id'              => $latestDecision->id,
                'decision'        => $latestDecision->decision,
                'status'          => $latestDecision->status,
                'constrained_qty' => $latestDecision->constrained_qty,
                'recommended_qty' => $latestDecision->recommended_qty,
                'ordered_qty'     => $latestDecision->ordered_qty,
                'received_qty'    => $latestDecision->received_qty,
                'days_of_cover'   => (float) $latestDecision->days_of_cover,
                'reorder_point'   => (float) $latestDecision->reorder_point,
                'forecast_demand' => (float) $latestDecision->forecast_demand,
                'safety_stock'    => $latestDecision->reasoning['safety_stock'] ?? null,
                'run_at'          => $latestDecision->run_at?->toDateTimeString(),
            ] : null,
            'decisionHistory' => $decisionHistory,
            'forecastModel'   => $registry ? [
                'model_name'                  => $registry->model_name,
                'demand_rate'                 => $registry->demand_rate,
                'forecast_horizon_days'       => $registry->forecast_horizon_days,
                'interval_lower'              => $registry->interval_lower,
                'interval_upper'              => $registry->interval_upper,
                'mae'                         => $registry->mae,
                'rmse'                        => $registry->rmse,
                'smape'                       => $registry->smape,
                'reeval_trigger'              => $registry->reeval_trigger,
                'selection_rationale'         => $registry->selection_rationale,
                'warnings'                    => $registry->warnings ?? [],
                'trained_at'                  => $registry->trained_at?->toDateTimeString(),
                'next_review_at'              => $registry->next_review_at?->toDateTimeString(),
            ] : null,
            'demandProfile' => $demandProfile ? [
                'volume_tier'          => $demandProfile->volume_tier,
                'volatility'           => $demandProfile->volatility,
                'intermittency'        => $demandProfile->intermittency,
                'seasonality_detected' => $demandProfile->seasonality_detected,
                'trend_direction'      => $demandProfile->trend_direction,
                'history_days_used'    => $demandProfile->history_days_used,
            ] : null,
            'feedbackMetrics' => $feedbackMetrics,
        ]);
    }

    public function update(UpdateSkuRequest $request, Sku $sku): RedirectResponse
    {
        $data = $request->validated();

        // unit_cost is stored as integer halalas; the form ships SAR.
        if (array_key_exists('unit_cost_sar', $data)) {
            $data['unit_cost'] = (int) round(((float) $data['unit_cost_sar']) * 100);
            unset($data['unit_cost_sar']);
        }

        // Allow operators to clear the override by leaving the field blank.
        if (array_key_exists('safety_stock_multiplier_override', $data)
            && $data['safety_stock_multiplier_override'] === '') {
            $data['safety_stock_multiplier_override'] = null;
        }

        $sku->update($data);

        return back();
    }

    public function summary(Sku $sku): JsonResponse
    {
        $sku->load('supplier');
        $latest = $sku->decisions()->latest('run_at')->first();

        return response()->json([
            'id'                 => $sku->id,
            'sku_code'           => $sku->sku_code,
            'name'               => $sku->name,
            'category'           => $sku->category,
            'supplier_id'        => $sku->supplier_id,
            'supplier_name'      => $sku->supplier->name,
            'unit_cost_sar'      => $sku->unit_cost_sar,
            'moq'                => $sku->moq,
            'lead_time_days'     => $sku->lead_time_days,
            'abc_class'          => $sku->abc_class,
            'xyz_class'          => $sku->xyz_class,
            'safety_stock_multiplier_override' => $sku->safety_stock_multiplier_override !== null
                ? (float) $sku->safety_stock_multiplier_override
                : null,
            'current_stock'      => $sku->current_stock,
            'in_transit_qty'     => $sku->in_transit_qty,
            'reserved_qty'       => $sku->reserved_qty,
            'effective_position' => $sku->effective_position,
            'decision'           => $latest?->decision,
            'constrained_qty'    => $latest?->constrained_qty,
            'days_of_cover'      => $latest ? (float) $latest->days_of_cover : null,
            'reorder_point'      => $latest?->reorder_point,
            'forecast_demand'    => $latest?->forecast_demand,
            'safety_stock'       => $latest ? ($latest->reasoning['safety_stock'] ?? null) : null,
            'run_at'             => $latest?->run_at?->toDateTimeString(),
        ]);
    }
}
