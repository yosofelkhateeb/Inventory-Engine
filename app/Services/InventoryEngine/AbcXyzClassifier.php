<?php

namespace App\Services\InventoryEngine;

use App\Models\SalesHistory;
use App\Models\Sku;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AbcXyzClassifier
{
    private const LOOKBACK_DAYS = 90;

    public function classify(Collection $skus): void
    {
        $revenues   = $this->computeRevenues($skus);
        $total      = $revenues->sum();
        $abcClasses = $this->assignAbcClasses($revenues, $total);

        foreach ($skus as $sku) {
            $sku->abc_class = $abcClasses[$sku->id] ?? 'C';
            $sku->xyz_class = $this->computeXyzClass($sku->id);
            $sku->save();
        }
    }

    public function getSafetyStockMultiplier(string $abc, string $xyz): float
    {
        return match ("{$abc}{$xyz}") {
            'AZ' => 1.5,
            'AY' => 1.2,
            'CZ' => 0.8,
            default => 1.0,
        };
    }

    private function computeRevenues(Collection $skus): Collection
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        return $skus->mapWithKeys(function (Sku $sku) use ($since) {
            $units = SalesHistory::where('sku_id', $sku->id)
                ->where('sale_date', '>=', $since)
                ->sum('quantity_sold');

            return [$sku->id => $units * $sku->unit_cost];
        });
    }

    private function assignAbcClasses(Collection $revenues, int|float $total): array
    {
        $sorted     = $revenues->sortDesc();
        $cumulative = 0;
        $classes    = [];

        foreach ($sorted as $skuId => $revenue) {
            $shareBefore = $total > 0 ? $cumulative / $total : 1.0;
            $cumulative += $revenue;

            $classes[$skuId] = match (true) {
                $shareBefore < 0.70 => 'A',
                $shareBefore < 0.90 => 'B',
                default             => 'C',
            };
        }

        return $classes;
    }

    private function computeXyzClass(int $skuId): string
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS);

        $qtys = SalesHistory::where('sku_id', $skuId)
            ->where('sale_date', '>=', $since)
            ->pluck('quantity_sold')
            ->map(fn ($q) => (float) $q);

        if ($qtys->isEmpty()) {
            return 'Z';
        }

        $mean = $qtys->average();

        if ($mean == 0.0) {
            return 'Z';
        }

        $variance = $qtys->average(fn ($q) => ($q - $mean) ** 2);
        $stddev   = sqrt($variance);
        $cv       = $stddev / $mean;

        return match (true) {
            $cv < 0.5  => 'X',
            $cv <= 1.0 => 'Y',
            default    => 'Z',
        };
    }
}
