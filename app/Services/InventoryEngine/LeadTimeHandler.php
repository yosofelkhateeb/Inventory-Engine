<?php

namespace App\Services\InventoryEngine;

use App\Models\LeadTimeObservation;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Services\InventoryEngine\DTOs\LeadTimeEstimate;
use Carbon\Carbon;

/**
 * Resolves the lead-time estimate the engine should use for a given SKU.
 *
 * Fallback ladder, most-precise to least:
 *   1. Per-SKU observations (lead_time_observations within trailing window
 *      with at least min_observations rows). Captures supplier behaviour
 *      specific to this SKU — useful for drop-ship vs warehouse-routed
 *      products from the same supplier.
 *   2. Per-supplier observations (same query without the sku_id filter).
 *      Picks up supplier reliability when an individual SKU doesn't have
 *      enough orders to produce a stable estimate yet.
 *   3. Static fallback (Supplier::avg_lead_time_days + lead_time_stddev).
 *      Used at cold start before any observations have accumulated.
 *
 * The returned LeadTimeEstimate carries a `source` field identifying which
 * rung produced it — visible in InventoryDecision::reasoning for ops
 * debugging.
 *
 * Closes the last data-derivation gap in the engine. Per the architectural
 * principle: any quantity that can be observed should derive itself from
 * observations, with statics surviving only as cold-start fallbacks.
 */
class LeadTimeHandler
{
    private const FALLBACK_BUFFER_MULTIPLIER = 1.3;

    public function getLeadTimeWithBuffer(int $supplierId, ?int $skuId = null, ?int $tenantId = null): LeadTimeEstimate
    {
        $tenantId ??= 1;
        $window   = (int) SystemSetting::get($tenantId, 'lead_time.observation_window_days',     365);
        $minObs   = (int) SystemSetting::get($tenantId, 'lead_time.min_observations_for_dynamic', 5);

        $cutoff = Carbon::today()->subDays($window);

        // ── Rung 1: per-SKU observations ─────────────────────────────────
        if ($skuId !== null) {
            $skuDays = $this->collectDays($supplierId, $skuId, $cutoff, $tenantId);
            if (count($skuDays) >= $minObs) {
                return $this->buildEstimate($skuDays, 'observations_sku');
            }
        }

        // ── Rung 2: per-supplier observations ────────────────────────────
        $supplierDays = $this->collectDays($supplierId, null, $cutoff, $tenantId);
        if (count($supplierDays) >= $minObs) {
            return $this->buildEstimate($supplierDays, 'observations_supplier');
        }

        // ── Rung 3: static fallback ──────────────────────────────────────
        $supplier = Supplier::findOrFail($supplierId);
        $expected = (int) $supplier->avg_lead_time_days;
        $stddev   = (float) $supplier->lead_time_stddev;

        $buffered = $stddev > 0
            ? (int) ceil($expected + $stddev)
            : (int) ceil($expected * self::FALLBACK_BUFFER_MULTIPLIER);

        return new LeadTimeEstimate(
            expected_days: $expected,
            buffered_days: $buffered,
            stddev:        $stddev,
            p95:           null,
            source:        'static',
        );
    }

    /** @return list<int> days_actual values matching the filter */
    private function collectDays(int $supplierId, ?int $skuId, Carbon $cutoff, int $tenantId): array
    {
        $query = LeadTimeObservation::withoutGlobalScopes()
            ->where('tenant_id',   $tenantId)
            ->where('supplier_id', $supplierId)
            ->where('order_received_at', '>=', $cutoff->format('Y-m-d'));

        if ($skuId !== null) {
            $query->where('sku_id', $skuId);
        }

        return $query->pluck('days_actual')->map(fn ($d) => (int) $d)->all();
    }

    /** @param list<int> $days */
    private function buildEstimate(array $days, string $source): LeadTimeEstimate
    {
        $n = count($days);
        $mean = array_sum($days) / $n;
        $variance = 0.0;
        foreach ($days as $d) {
            $variance += ($d - $mean) ** 2;
        }
        $stddev = $n > 1 ? sqrt($variance / $n) : 0.0;

        // Buffered = mean + 1σ (95% ish for normal-ish distributions; the
        // stronger ceiling on the watch threshold uses p95 directly).
        $buffered = $stddev > 0
            ? (int) ceil($mean + $stddev)
            : (int) ceil($mean * self::FALLBACK_BUFFER_MULTIPLIER);

        return new LeadTimeEstimate(
            expected_days: (int) round($mean),
            buffered_days: $buffered,
            stddev:        round($stddev, 4),
            p95:           round($this->percentile($days, 95), 2),
            source:        $source,
        );
    }

    /** Linear-interpolation percentile (matches numpy default). */
    private function percentile(array $values, float $p): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float) $values[0];

        $rank = ($p / 100) * ($n - 1);
        $low  = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) return (float) $values[$low];
        return $values[$low] + ($rank - $low) * ($values[$high] - $values[$low]);
    }
}
