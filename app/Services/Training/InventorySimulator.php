<?php

namespace App\Services\Training;

use App\Models\InventoryPositionSnapshot;
use App\Models\LeadTimeObservation;
use App\Models\Sku;
use App\Models\StockoutEvent;
use App\Models\Supplier;
use App\Services\InventoryEngine\DecisionScorer;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;
use App\Services\InventoryEngine\DTOs\ForecastResult;
use App\Services\InventoryEngine\DTOs\InventoryPosition;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Day-by-day inventory dynamics simulator. Produces ground truth for
 * Chunk 3's calibration loop:
 *
 *   sales_history  →  InventorySimulator  →  {lead_time_observations,
 *                                              stockout_events,
 *                                              inventory_position_snapshots}
 *
 * The output is internally consistent: every stockout event has a
 * preceding ROP-crossing snapshot and a corresponding lead-time
 * observation when stock recovers. This matters because Chunk 3
 * trains on cross-table joins; if the rows didn't agree, the
 * calibration target would be noise.
 *
 * Decision logic mirrors production: the same DecisionScorer the
 * engine uses computes each day's snapshot decision. Snapshots
 * therefore reflect what the engine WOULD have decided each day —
 * the exact training signal we want.
 */
class InventorySimulator
{
    /**
     * Lead-time distribution: log-normal around the supplier's mean.
     * Coefficient of variation = stddev / mean. 0.30 reflects typical
     * supplier reliability — 70% of orders within ±30% of expected.
     */
    private const LEAD_TIME_CV = 0.30;

    /**
     * Reorder rule: when effective_position <= ROP, place an order
     * for max(reorder_qty, moq) units.
     */

    /**
     * Recent-demand window for the rolling daily-demand estimate that
     * feeds ROP. Larger window = smoother estimate, less responsive
     * to shifts.
     */
    private const DAILY_DEMAND_WINDOW_DAYS = 30;

    /**
     * Window for the proxy sMAPE: rolling moving-average residual.
     * Mimics how the production pipeline's CV-based sMAPE behaves
     * without requiring us to run Python over historical data.
     */
    private const SMAPE_WINDOW_DAYS = 60;

    /**
     * Window for trend detection (linear regression on recent demand).
     */
    private const TREND_WINDOW_DAYS = 90;

    private \Random\Randomizer $rng;

    public function __construct(
        private readonly DecisionScorer $scorer,
        private readonly int $tenantId = 1,
        int $seed = 42,
    ) {
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }

    /**
     * Simulate `$sku` from `$start` to `$end`. `$dailyDemand` is a map
     * of "Y-m-d" → units sold that day (zero-fill missing days externally
     * if you need that semantics). Returns counts of rows inserted.
     */
    public function simulate(
        Sku $sku,
        Supplier $supplier,
        array $dailyDemand,
        Carbon $start,
        Carbon $end,
    ): array {
        $period = CarbonPeriod::create($start, $end);

        // ── State ─────────────────────────────────────────────────────────────
        // Initial on_hand: 30 days of mean demand. Realistic-ish opening
        // position; the simulator's first month is effectively a warm-up.
        $meanDemand   = $this->meanDemand($dailyDemand);
        $onHand       = max(1, (int) round($meanDemand * 30));
        $reserved     = 0;

        // Pending arrivals: keyed by arrival date (Y-m-d) → ['qty' => int, 'placed' => Carbon]
        $pendingArrivals = [];

        // Active stockout, or null
        $activeStockout = null;

        // Counters
        $observations = [];
        $stockouts    = [];
        $snapshots    = [];

        $supplierLeadMean   = (float) ($sku->lead_time_days ?? $supplier->avg_lead_time_days ?? 7);
        $supplierLeadStddev = $supplierLeadMean * self::LEAD_TIME_CV;
        $reorderQty         = max($sku->reorder_qty ?? 0, $sku->moq ?? 0, 1);

        foreach ($period as $day) {
            $dateKey = $day->format('Y-m-d');

            // 1. Receive arrivals scheduled for today
            if (isset($pendingArrivals[$dateKey])) {
                foreach ($pendingArrivals[$dateKey] as $arrival) {
                    $onHand += $arrival['qty'];
                    $observations[] = $this->observationRow($sku, $supplier, $arrival, $day);
                }
                unset($pendingArrivals[$dateKey]);
            }

            // 2. Apply demand. If insufficient, accrue lost demand.
            $demand = (int) ($dailyDemand[$dateKey] ?? 0);
            $lost   = max(0, $demand - $onHand);
            $served = $demand - $lost;
            $onHand = max(0, $onHand - $served);

            // 3. Stockout dynamics
            if ($onHand <= 0 && $activeStockout === null) {
                // Stockout begins today. Record opening row.
                $activeStockout = [
                    'occurred_at'       => $day->copy(),
                    'demand_lost_units' => $lost,
                    'sku_id'            => $sku->id,
                ];
            } elseif ($onHand <= 0 && $activeStockout !== null) {
                $activeStockout['demand_lost_units'] += $lost;
            } elseif ($onHand > 0 && $activeStockout !== null) {
                // Recovery
                $stockouts[] = [
                    'tenant_id'         => $this->tenantId,
                    'sku_id'            => $sku->id,
                    'occurred_at'       => $activeStockout['occurred_at']->format('Y-m-d'),
                    'recovered_at'      => $day->format('Y-m-d'),
                    'duration_days'     => $activeStockout['occurred_at']->diffInDays($day),
                    'demand_lost_units' => $activeStockout['demand_lost_units'],
                    'source'            => 'synthetic',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
                $activeStockout = null;
            }

            // 4. Recompute rolling demand stats and run the production
            //    decision logic for this day.
            $dailyDemandRate    = $this->rollingMean($dailyDemand, $day, self::DAILY_DEMAND_WINDOW_DAYS);
            $dailyDemandStddev  = $this->rollingStddev($dailyDemand, $day, self::DAILY_DEMAND_WINDOW_DAYS, $dailyDemandRate);
            $smapeProxy         = $this->rollingSmapeProxy($dailyDemand, $day, self::SMAPE_WINDOW_DAYS);
            $trendDirection     = $this->rollingTrend($dailyDemand, $day, self::TREND_WINDOW_DAYS);

            $inTransit = array_sum(array_column(array_merge(...array_values($pendingArrivals)) ?: [[]], 'qty'));
            $effective = $onHand + $inTransit - $reserved;

            $forecast = new ForecastResult(
                daily_demand:    round($dailyDemandRate, 4),
                demand_stddev:   round($dailyDemandStddev, 4),
                horizon_demand:  round($dailyDemandRate * 30, 4),
                horizon_days:    30,
                method:          'simulated',
                smape:           $smapeProxy,
                trend_direction: $trendDirection,
            );
            $position = new InventoryPosition($onHand, $inTransit, $reserved, $effective, $dailyDemandRate > 0 ? $effective / $dailyDemandRate : 999);
            $leadTime = new LeadTimeEstimate(
                expected_days: (int) round($supplierLeadMean),
                buffered_days: (int) round($supplierLeadMean * 1.15),
                stddev:        $supplierLeadStddev,
            );
            $constraints = new ConstrainedQuantity($reorderQty, $reorderQty, false, []);

            $decision = $this->scorer->score($position, $forecast, $leadTime, $constraints, 1.0, $this->tenantId);

            // 5. If decision = order, place an arrival on a sampled future date
            if ($decision->decision === 'order') {
                $leadDays = $this->sampleLeadTime($supplierLeadMean, $supplierLeadStddev);
                $arrivalDate = $day->copy()->addDays($leadDays);
                $arrivalKey  = $arrivalDate->format('Y-m-d');

                $pendingArrivals[$arrivalKey] ??= [];
                $pendingArrivals[$arrivalKey][] = [
                    'qty'    => $reorderQty,
                    'placed' => $day->copy(),
                    'lead'   => $leadDays,
                ];
            }

            // 6. Record snapshot
            $snapshots[] = [
                'tenant_id'                 => $this->tenantId,
                'sku_id'                    => $sku->id,
                'snapshot_date'             => $dateKey,
                'on_hand'                   => $onHand,
                'in_transit'                => $inTransit,
                'reserved'                  => $reserved,
                'effective_position'        => $effective,
                'reorder_point'             => $decision->reorder_point,
                'daily_demand'              => round($dailyDemandRate, 4),
                'demand_stddev'             => round($dailyDemandStddev, 4),
                'lead_time_days'            => (int) round($supplierLeadMean),
                'lead_time_stddev'          => round($supplierLeadStddev, 4),
                'smape'                     => $smapeProxy,
                'trend_direction'           => $trendDirection,
                'decision'                  => $decision->decision,
                'reorder_within_threshold'  => null,  // populated retroactively by Chunk 3
                'stockout_within_threshold' => null,  // populated retroactively by Chunk 3
                'created_at'                => now(),
                'updated_at'                => now(),
            ];
        }

        // Close any still-open stockout at end-of-period (recovered_at = null,
        // duration null — calibration treats this as in-progress)
        if ($activeStockout !== null) {
            $stockouts[] = [
                'tenant_id'         => $this->tenantId,
                'sku_id'            => $sku->id,
                'occurred_at'       => $activeStockout['occurred_at']->format('Y-m-d'),
                'recovered_at'      => null,
                'duration_days'     => null,
                'demand_lost_units' => $activeStockout['demand_lost_units'],
                'source'            => 'synthetic',
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }

        // ── Bulk persist ──────────────────────────────────────────────────────
        $this->bulkInsert(LeadTimeObservation::class,    $observations, 500);
        $this->bulkInsert(StockoutEvent::class,          $stockouts,    500);
        $this->bulkInsert(InventoryPositionSnapshot::class, $snapshots,  500);

        return [
            'lead_times' => count($observations),
            'stockouts'  => count($stockouts),
            'snapshots'  => count($snapshots),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function observationRow(Sku $sku, Supplier $supplier, array $arrival, Carbon $arrivedOn): array
    {
        return [
            'tenant_id'         => $this->tenantId,
            'supplier_id'       => $supplier->id,
            'sku_id'            => $sku->id,
            'order_placed_at'   => $arrival['placed']->format('Y-m-d'),
            'order_received_at' => $arrivedOn->format('Y-m-d'),
            'days_actual'       => $arrival['lead'],
            'source'            => 'synthetic',
            'created_at'        => now(),
            'updated_at'        => now(),
        ];
    }

    /**
     * Sample a lead time from a log-normal-ish distribution. Most orders
     * cluster near the mean; long tails capture occasional supplier delays.
     */
    private function sampleLeadTime(float $mean, float $stddev): int
    {
        if ($mean <= 0) {
            return 1;
        }
        // Box-Muller draw, then exponentiate to get a positive log-normal-ish value
        $u1 = $this->rng->getFloat(0.0, 1.0, \Random\IntervalBoundary::OpenOpen);
        $u2 = $this->rng->getFloat(0.0, 1.0, \Random\IntervalBoundary::OpenOpen);
        $z  = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        $sample = $mean + $z * $stddev;
        return max(1, (int) round($sample));
    }

    private function meanDemand(array $dailyDemand): float
    {
        if (empty($dailyDemand)) {
            return 0.0;
        }
        return array_sum($dailyDemand) / count($dailyDemand);
    }

    private function rollingMean(array $dailyDemand, Carbon $day, int $window): float
    {
        $sum = 0.0;
        $n   = 0;
        for ($i = 1; $i <= $window; $i++) {
            $key = $day->copy()->subDays($i)->format('Y-m-d');
            if (isset($dailyDemand[$key])) {
                $sum += $dailyDemand[$key];
                $n++;
            }
        }
        return $n > 0 ? $sum / $n : 0.0;
    }

    private function rollingStddev(array $dailyDemand, Carbon $day, int $window, float $mean): float
    {
        $variance = 0.0;
        $n        = 0;
        for ($i = 1; $i <= $window; $i++) {
            $key = $day->copy()->subDays($i)->format('Y-m-d');
            if (isset($dailyDemand[$key])) {
                $variance += ($dailyDemand[$key] - $mean) ** 2;
                $n++;
            }
        }
        return $n > 1 ? sqrt($variance / $n) : 0.0;
    }

    /**
     * Rolling proxy sMAPE: rolling-mean as the predictor, compared against
     * actual. Mirrors how the production pipeline's CV-based metric behaves
     * without requiring us to run Python on historical data.
     */
    private function rollingSmapeProxy(array $dailyDemand, Carbon $day, int $window): ?float
    {
        $errors = [];
        for ($i = 1; $i <= $window; $i++) {
            $key       = $day->copy()->subDays($i)->format('Y-m-d');
            $predKey   = $day->copy()->subDays($i + 7)->format('Y-m-d');
            if (! isset($dailyDemand[$key]) || ! isset($dailyDemand[$predKey])) {
                continue;
            }
            $actual = $dailyDemand[$key];
            $pred   = $this->rollingMean($dailyDemand, Carbon::parse($key), 7);  // 7-day MA prediction
            $denom  = abs($actual) + abs($pred);
            $errors[] = $denom > 0 ? 2 * abs($actual - $pred) / $denom : 0.0;
        }
        if (count($errors) < 5) {
            return null;
        }
        return round((array_sum($errors) / count($errors)) * 100, 2);
    }

    /**
     * Rolling trend direction via linear regression on recent demand.
     * Returns 'upward' / 'declining' / 'flat'.
     */
    private function rollingTrend(array $dailyDemand, Carbon $day, int $window): string
    {
        $points = [];
        for ($i = $window - 1; $i >= 0; $i--) {
            $key = $day->copy()->subDays($i + 1)->format('Y-m-d');
            if (isset($dailyDemand[$key])) {
                $points[] = ['x' => $window - $i, 'y' => (float) $dailyDemand[$key]];
            }
        }
        if (count($points) < 10) {
            return 'flat';
        }

        $n = count($points);
        $sumX = array_sum(array_column($points, 'x'));
        $sumY = array_sum(array_column($points, 'y'));
        $sumXY = 0.0;
        $sumXX = 0.0;
        foreach ($points as $p) {
            $sumXY += $p['x'] * $p['y'];
            $sumXX += $p['x'] * $p['x'];
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        if ($denom == 0.0) {
            return 'flat';
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $denom;

        // "Significant" trend = slope magnitude > 1% of mean per day
        $meanY = $sumY / $n;
        $threshold = max(0.001, abs($meanY) * 0.01);

        return match (true) {
            $slope >  $threshold => 'upward',
            $slope < -$threshold => 'declining',
            default              => 'flat',
        };
    }

    private function bulkInsert(string $modelClass, array $rows, int $chunkSize): void
    {
        if (empty($rows)) {
            return;
        }
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $modelClass::withoutGlobalScopes()->insert($chunk);
        }
    }
}
