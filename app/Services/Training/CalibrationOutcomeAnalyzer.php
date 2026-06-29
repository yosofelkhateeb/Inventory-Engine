<?php

namespace App\Services\Training;

use App\Models\InventoryPositionSnapshot;
use App\Models\LeadTimeObservation;
use App\Models\StockoutEvent;
use Carbon\Carbon;

/**
 * Given a candidate (k_lead, k_ltv, k_smape, k_trend) tuple, classify
 * every above-ROP snapshot in the training set into:
 *
 *   TP — would have been WATCH AND a reorder event followed within the
 *        watch window (the threshold itself is the window — "did the watch
 *        correctly predict an event within its claimed window?")
 *   FP — would have been WATCH AND no event in the window
 *   TN — would have been HOLD AND no event in the window
 *   FN — would have been HOLD AND a stockout occurred in the window
 *
 * Below-ROP snapshots (decision = 'order' regardless of coefficients) are
 * filtered out — they're not part of the watch/hold classification.
 *
 * Performance: pre-loads lead_time_observations and stockout_events into
 * in-memory maps keyed by sku_id, so each snapshot's outcome lookup is
 * O(events_for_sku) instead of two DB queries. For a 30-SKU × 3-year
 * dataset this is ~33k snapshots evaluated in roughly one second per
 * candidate tuple.
 *
 * Mirrors the formula in DecisionScorer::watchThresholdDays exactly so
 * calibration converges on coefficients the production engine will use.
 */
class CalibrationOutcomeAnalyzer
{
    private const MIN_DAYS                = 1.0;
    private const GLOBAL_CEILING_DAYS     = 90.0;

    /** Cached per-(tenantId) — sku_id => list<['placed_at'=>string]> */
    private array $reordersBySku = [];

    /** Cached per-(tenantId) — sku_id => list<['occurred_at'=>string]> */
    private array $stockoutsBySku = [];

    public function evaluate(
        int    $tenantId,
        float  $kLead,
        float  $kLtv,
        float  $kSmape,
        float  $kTrend,
        ?Carbon $startDate = null,
        ?Carbon $endDate   = null,
    ): array {
        $this->loadGroundTruth($tenantId);

        $tp = 0; $fp = 0; $tn = 0; $fn = 0;
        $considered = 0;

        $query = InventoryPositionSnapshot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId);
        if ($startDate) $query->where('snapshot_date', '>=', $startDate->format('Y-m-d'));
        if ($endDate)   $query->where('snapshot_date', '<=', $endDate->format('Y-m-d'));

        $query->orderBy('snapshot_date')->chunk(2000, function ($chunk) use (
            $kLead, $kLtv, $kSmape, $kTrend, &$tp, &$fp, &$tn, &$fn, &$considered,
        ): void {
            foreach ($chunk as $snap) {
                /** @var InventoryPositionSnapshot $snap */
                if ($snap->daily_demand <= 0) {
                    continue; // no-demand SKUs aren't part of watch logic
                }
                $bufferDays = ($snap->effective_position - $snap->reorder_point) / $snap->daily_demand;
                if ($bufferDays < 0) {
                    continue; // below ROP → order, not in watch/hold space
                }

                $threshold = $this->thresholdDays(
                    leadMean:   (float) ($snap->lead_time_days ?? 7),
                    leadStddev: (float) ($snap->lead_time_stddev ?? 0),
                    smape:      $snap->smape !== null ? (float) $snap->smape : null,
                    trend:      $snap->trend_direction,
                    kLead:      $kLead,
                    kLtv:       $kLtv,
                    kSmape:     $kSmape,
                    kTrend:     $kTrend,
                );

                $hypotheticalDecision = $bufferDays <= $threshold ? 'watch' : 'hold';

                $endOfWindow = (string) Carbon::parse($snap->snapshot_date)
                    ->addDays((int) ceil($threshold))->format('Y-m-d');
                $startOfWindow = (string) $snap->snapshot_date;

                $hadReorder  = $this->hasReorderInWindow($snap->sku_id, $startOfWindow, $endOfWindow);
                $hadStockout = $this->hasStockoutInWindow($snap->sku_id, $startOfWindow, $endOfWindow);

                $considered++;
                if ($hypotheticalDecision === 'watch') {
                    if ($hadReorder || $hadStockout) {
                        $tp++;
                    } else {
                        $fp++;
                    }
                } else {
                    if ($hadStockout) {
                        $fn++;
                    } else {
                        $tn++;
                    }
                }
            }
        });

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall    = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1        = ($precision + $recall) > 0
            ? 2 * $precision * $recall / ($precision + $recall)
            : 0.0;

        // F2 weights recall ~4× higher — appropriate when missed stockouts
        // are operationally worse than unnecessary watches. Surfaced
        // alongside F1 so the optimizer can pick its objective.
        $f2 = ($precision + $recall) > 0
            ? 5 * $precision * $recall / (4 * $precision + $recall)
            : 0.0;

        return [
            'tp' => $tp, 'fp' => $fp, 'tn' => $tn, 'fn' => $fn,
            'precision'   => round($precision, 4),
            'recall'      => round($recall,    4),
            'f1'          => round($f1,        4),
            'f2'          => round($f2,        4),
            'n_snapshots' => $considered,
        ];
    }

    /** Mirror of DecisionScorer::watchThresholdDays. */
    private function thresholdDays(
        float $leadMean,
        float $leadStddev,
        ?float $smape,
        ?string $trend,
        float $kLead,
        float $kLtv,
        float $kSmape,
        float $kTrend,
    ): float {
        $smapeFrac = $smape !== null ? $smape / 100.0 : 0.0;
        $trendFactor = match ($trend) {
            'upward'    => +1.0,
            'declining' => -1.0,
            default     => 0.0,
        };

        $threshold = ($leadMean * $kLead)
                   + ($leadStddev * $kLtv)
                   + ($smapeFrac * $leadMean * $kSmape)
                   + ($trendFactor * $kTrend);

        $perSkuCeiling = max(self::MIN_DAYS, $leadMean * 2.0);
        $ceiling       = min(self::GLOBAL_CEILING_DAYS, $perSkuCeiling);
        return max(self::MIN_DAYS, min($threshold, $ceiling));
    }

    private function loadGroundTruth(int $tenantId): void
    {
        if (! empty($this->reordersBySku) || ! empty($this->stockoutsBySku)) {
            return;
        }
        LeadTimeObservation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('order_placed_at')
            ->chunk(5000, function ($chunk): void {
                foreach ($chunk as $obs) {
                    $this->reordersBySku[$obs->sku_id][] = $obs->order_placed_at->format('Y-m-d');
                }
            });
        StockoutEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('occurred_at')
            ->chunk(5000, function ($chunk): void {
                foreach ($chunk as $evt) {
                    $this->stockoutsBySku[$evt->sku_id][] = $evt->occurred_at->format('Y-m-d');
                }
            });
    }

    private function hasReorderInWindow(int $skuId, string $start, string $end): bool
    {
        $reorders = $this->reordersBySku[$skuId] ?? [];
        foreach ($reorders as $date) {
            if ($date >= $start && $date <= $end) {
                return true;
            }
        }
        return false;
    }

    private function hasStockoutInWindow(int $skuId, string $start, string $end): bool
    {
        $stockouts = $this->stockoutsBySku[$skuId] ?? [];
        foreach ($stockouts as $date) {
            if ($date >= $start && $date <= $end) {
                return true;
            }
        }
        return false;
    }
}
