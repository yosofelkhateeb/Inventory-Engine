<?php

namespace App\Services\InventoryEngine;

use App\Models\EngineRun;
use App\Models\InventoryDecision;
use App\Models\Sku;
use App\Services\InventoryEngine\DTOs\Decision;

class InventoryEngineService
{
    public function __construct(
        private readonly DemandForecaster         $forecaster,
        private readonly InventoryPositionTracker $tracker,
        private readonly LeadTimeHandler          $leadTimeHandler,
        private readonly ConstraintEngine         $constraints,
        private readonly DecisionScorer           $scorer,
        private readonly AbcXyzClassifier         $classifier,
        private readonly DecisionStatusService    $statusService,
    ) {}

    /** @return Decision[] */
    public function run(int $budgetRemainingHalalas = PHP_INT_MAX, ?int $triggeredBy = null): array
    {
        $engineRun = EngineRun::create([
            'triggered_by'    => $triggeredBy,
            'run_at'          => now(),
            'status'          => 'running',
            'decisions_count' => 0,
        ]);

        $startMs = (int) (microtime(true) * 1000);

        try {
            $skus  = Sku::with('supplier')->get();
            $runAt = now();

            $this->classifier->classify($skus);
            // Reload abc_class / xyz_class from DB after classifier writes them
            $skus = Sku::with('supplier')->get();

            $results = [];

            foreach ($skus as $sku) {
                $forecast    = $this->forecaster->forecast($sku->id, 30, $sku->tenant_id);
                $position    = $this->tracker->getPosition($sku->id, $forecast->daily_demand);
                $leadTime    = $this->leadTimeHandler->getLeadTimeWithBuffer($sku->supplier_id, $sku->id, $sku->tenant_id);
                $rawQty      = max($sku->reorder_qty, $sku->moq);
                $constrained = $this->constraints->applyConstraints($sku->id, $rawQty, $budgetRemainingHalalas);
                // Operator override (set per SKU on the edit form) wins over the
                // engine-derived multiplier from ABC/XYZ classification. NULL =
                // use engine default.
                $multiplier  = $sku->safety_stock_multiplier_override !== null
                    ? (float) $sku->safety_stock_multiplier_override
                    : $this->classifier->getSafetyStockMultiplier(
                        $sku->abc_class ?? 'C',
                        $sku->xyz_class ?? 'Z',
                    );
                $decision    = $this->scorer->score($position, $forecast, $leadTime, $constrained, $multiplier, $sku->tenant_id);

                $this->persist($sku->id, $sku->tenant_id, $decision, $forecast, $position, $runAt);

                if ($decision->decision === 'order') {
                    $budgetRemainingHalalas -= $decision->constrained_qty * $sku->unit_cost;
                }

                $results[] = $decision;
            }

            $engineRun->update([
                'status'          => 'completed',
                'decisions_count' => count($results),
                'duration_ms'     => (int) (microtime(true) * 1000) - $startMs,
            ]);

            return $results;
        } catch (\Throwable $e) {
            $engineRun->update([
                'status'      => 'failed',
                'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            ]);
            throw $e;
        }
    }

    private function persist(int $skuId, int $tenantId, Decision $decision, $forecast, $position, $runAt): void
    {
        $supersededIds = $this->statusService->supersedePending($skuId, $tenantId);

        $newDecision = InventoryDecision::create([
            'sku_id'          => $skuId,
            'run_at'          => $runAt,
            'decision'        => $decision->decision,
            'recommended_qty' => $decision->recommended_qty,
            'constrained_qty' => $decision->constrained_qty,
            'reasoning'       => $decision->reasoning,
            'forecast_demand' => $forecast->daily_demand,
            'days_of_cover'   => $position->days_of_cover,
            'reorder_point'   => $decision->reorder_point,
        ]);

        if ($supersededIds) {
            InventoryDecision::withoutGlobalScopes()
                ->whereIn('id', $supersededIds)
                ->update(['superseded_by_decision_id' => $newDecision->id]);
        }
    }
}
