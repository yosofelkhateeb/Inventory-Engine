<?php

namespace App\Services\InventoryEngine;

use App\Models\SystemSetting;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;
use App\Services\InventoryEngine\DTOs\Decision;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use App\Services\InventoryEngine\DTOs\InventoryPosition;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;

/**
 * Watch decision: multi-factor, days-based formula.
 *
 * Buffer is computed in days of demand:
 *   buffer_days = (effective_position - reorder_point) / daily_demand
 *
 * The watch threshold is the SKU-specific number of days below which
 * we want advance warning. It scales with operationally-meaningful
 * factors rather than a global percentage:
 *
 *   threshold = lead_time_mean   * k_lead
 *             + lead_time_stddev * k_ltv
 *             + smape * lead_time_mean * k_smape
 *             + trend_factor * k_trend
 *
 * with min_floor and per-SKU ceiling clipping.
 *
 * Coefficients are stored in system_settings and intended to be calibrated
 * from real client data once it's connected (Chunks 2-4 of the rollout).
 * Current defaults are priors from inventory science:
 *   k_lead  = 0.5   — half the lead time as base watch window
 *   k_ltv   = 1.65  — 95% confidence multiplier on lead time variance
 *   k_smape = 0.5   — half-weight on forecast error (in days proportional to lead time)
 *   k_trend = 0.0   — disabled in cold start (turn on after calibration)
 *
 * Defaults sourced from Silver, Pyke, and Peterson, "Inventory Management
 * and Production Planning and Scheduling" (3rd ed.), Wiley 1998 — see
 * docs/INVENTORY_ENGINE.md for the full rationale.
 */
class DecisionScorer
{
    private const Z_SCORE = 1.65; // 95% service level

    public function score(
        InventoryPosition   $position,
        ForecastResult      $forecast,
        LeadTimeEstimate    $leadTime,
        ConstrainedQuantity $constraints,
        float               $safetyStockMultiplier = 1.0,
        ?int                $tenantId              = null,
    ): Decision {
        $safetyStock  = self::Z_SCORE * $forecast->demand_stddev * sqrt($leadTime->buffered_days) * $safetyStockMultiplier;
        $reorderPoint = (int) floor(($forecast->daily_demand * $leadTime->buffered_days) + $safetyStock);

        $effective = $position->effective_position;
        $reasoning = [
            'effective_position' => $effective,
            'reorder_point'      => $reorderPoint,
            'safety_stock'       => round($safetyStock, 2),
            'daily_demand'       => $forecast->daily_demand,
            'buffered_lead_time' => $leadTime->buffered_days,
            'constraint_notes'   => $constraints->constraint_notes,
        ];

        // Below ROP — order regardless of buffer-days threshold.
        if ($effective <= $reorderPoint) {
            if ($constraints->budget_blocked) {
                return new Decision('order_budget_blocked', $constraints->raw_qty, 0, $reorderPoint, $safetyStock, $reasoning);
            }
            return new Decision('order', $constraints->raw_qty, $constraints->final_qty, $reorderPoint, $safetyStock, $reasoning);
        }

        // Compute buffer in days of demand. Zero or near-zero demand collapses
        // the calculation; in that case any positive buffer is "comfortable"
        // because no demand consumes it.
        if ($forecast->daily_demand <= 0.0) {
            return new Decision('hold', 0, 0, $reorderPoint, $safetyStock, $reasoning);
        }
        $bufferDays = ($effective - $reorderPoint) / $forecast->daily_demand;

        $threshold = $this->watchThresholdDays($leadTime, $forecast, $tenantId);

        $reasoning['buffer_days']        = round($bufferDays, 2);
        $reasoning['watch_threshold_days'] = round($threshold, 2);

        if ($bufferDays <= $threshold) {
            return new Decision('watch', 0, 0, $reorderPoint, $safetyStock, $reasoning);
        }

        return new Decision('hold', 0, 0, $reorderPoint, $safetyStock, $reasoning);
    }

    /**
     * Per-SKU watch-threshold in days, computed from operationally-meaningful
     * inputs. See class doc for the full formula and references.
     */
    private function watchThresholdDays(
        LeadTimeEstimate $leadTime,
        ForecastResult   $forecast,
        ?int             $tenantId,
    ): float {
        $kLead   = $this->setting($tenantId, 'decision.watch.k_lead',          0.5);
        $kLtv    = $this->setting($tenantId, 'decision.watch.k_ltv',           1.65);
        $kSmape  = $this->setting($tenantId, 'decision.watch.k_smape',         0.5);
        $kTrend  = $this->setting($tenantId, 'decision.watch.k_trend',         0.0);
        $minFloor      = $this->setting($tenantId, 'decision.watch.min_days',          1.0);
        $globalCeiling = $this->setting($tenantId, 'decision.watch.global_ceiling_days', 90.0);

        $leadMean   = (float) $leadTime->expected_days;
        $leadStddev = (float) $leadTime->stddev;
        $smapeFrac  = $forecast->smape !== null ? ((float) $forecast->smape) / 100.0 : 0.0;

        $trendFactor = match ($forecast->trend_direction) {
            'upward'    => +1.0,    // growing demand → widen buffer (catch sooner)
            'declining' => -1.0,    // shrinking demand → narrow buffer
            default     => 0.0,     // 'flat' or null
        };

        $threshold = ($leadMean   * $kLead)
                   + ($leadStddev * $kLtv)
                   + ($smapeFrac  * $leadMean * $kSmape)
                   + ($trendFactor * $kTrend);

        // Per-SKU ceiling: use p95 of historical lead-time observations
        // when available (LeadTimeHandler computes it from
        // lead_time_observations). Falls back to 2× expected lead time
        // for cold start when there aren't enough observations yet.
        $perSkuCeiling = $leadTime->p95 !== null
            ? max($minFloor, (float) $leadTime->p95)
            : max($minFloor, $leadMean * 2.0);

        $ceiling = min($globalCeiling, $perSkuCeiling);
        return max($minFloor, min($threshold, $ceiling));
    }

    private function setting(?int $tenantId, string $key, float $default): float
    {
        if ($tenantId === null) {
            return $default;
        }
        return (float) SystemSetting::get($tenantId, $key, $default);
    }
}
