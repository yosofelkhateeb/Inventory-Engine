<?php

namespace App\Services\Ingestion\Importers;

use App\Models\DataIngestionRun;
use App\Models\Sku;
use App\Services\Ingestion\IngestionSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesHistoryImporter
{
    public function import(
        IngestionSource $source,
        DataIngestionRun $run,
        int $tenantId,
        array $options = [],
    ): void {
        $dryRun = (bool) ($options['dry_run'] ?? false);

        // Pre-build sku_code → sku_id map for this tenant
        $skuMap = Sku::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'sku_code')
            ->all();

        $errors = [];
        $rowNum = 0;

        // Collect valid rows; aggregate by (sku_code, sale_date) to sum quantities
        $aggregated = []; // key: "sku_code|sale_date" → ['sku_id', 'sale_date', 'quantity_sold', 'is_promotion']

        foreach ($source->fetch('sales_history') as $raw) {
            $rowNum++;
            $row = $source->transform('sales_history', $raw);
            $rowErrors = $this->validate($row, $skuMap);

            if ($rowErrors) {
                $errors[] = ['row' => $rowNum, 'data' => $raw, 'errors' => $rowErrors];
                continue;
            }

            $key = "{$row['sku_code']}|{$row['sale_date']}";
            $skuId = $skuMap[$row['sku_code']];

            if (isset($aggregated[$key])) {
                $aggregated[$key]['quantity_sold'] += $row['quantity_sold'];
                // Once promotion, always promotion for that date
                $aggregated[$key]['is_promotion'] = $aggregated[$key]['is_promotion'] || $row['is_promotion'];
            } else {
                $aggregated[$key] = [
                    'sku_id'        => $skuId,
                    'sale_date'     => $row['sale_date'],
                    'quantity_sold' => $row['quantity_sold'],
                    'is_promotion'  => $row['is_promotion'],
                ];
            }
        }

        $succeeded = 0;

        if (! $dryRun && $aggregated) {
            DB::transaction(function () use ($aggregated, $tenantId, &$succeeded) {
                foreach ($aggregated as $row) {
                    DB::table('sales_history')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'sku_id'    => $row['sku_id'],
                            'sale_date' => $row['sale_date'],
                        ],
                        [
                            'tenant_id'     => $tenantId,
                            'quantity_sold' => $row['quantity_sold'],
                            'is_promotion'  => (int) $row['is_promotion'],
                            'updated_at'    => now(),
                        ],
                    );
                    $succeeded++;
                }
            });
        }

        $failed = count($errors);
        $status = match (true) {
            $failed > 0 && $succeeded > 0 => DataIngestionRun::STATUS_PARTIAL,
            $failed > 0 && $succeeded === 0 && $rowNum > 0 => DataIngestionRun::STATUS_FAILED,
            default => DataIngestionRun::STATUS_COMPLETED,
        };

        $run->update([
            'rows_processed' => $rowNum,
            'rows_succeeded'  => $succeeded,
            'rows_failed'     => $failed,
            'error_log'       => $errors ?: null,
            'status'          => $status,
            'completed_at'    => now(),
        ]);
    }

    private function validate(array $row, array $skuMap): array
    {
        $errors = [];

        if ($row['sku_code'] === '') {
            $errors[] = 'sku_code is required';
        } elseif (! array_key_exists($row['sku_code'], $skuMap)) {
            $errors[] = "sku_code '{$row['sku_code']}' not found — import SKUs first";
        }

        if ($row['sale_date'] === '') {
            $errors[] = 'sale_date is required';
        } else {
            try {
                $date = Carbon::parse($row['sale_date']);
                if ($date->isFuture()) {
                    $errors[] = 'sale_date cannot be in the future';
                }
            } catch (\Exception) {
                $errors[] = 'sale_date must be a valid ISO-8601 date (YYYY-MM-DD)';
            }
        }

        if ($row['quantity_sold'] < 0) {
            $errors[] = 'quantity_sold must be non-negative';
        }

        return $errors;
    }
}
