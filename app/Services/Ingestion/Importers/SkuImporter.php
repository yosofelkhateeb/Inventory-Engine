<?php

namespace App\Services\Ingestion\Importers;

use App\Models\DataIngestionRun;
use App\Models\Sku;
use App\Models\Supplier;
use App\Services\Ingestion\IngestionSource;
use Illuminate\Support\Facades\DB;

class SkuImporter
{
    private const VALID_CATEGORIES = ['equipment', 'accessory', 'bundle'];

    public function import(
        IngestionSource $source,
        DataIngestionRun $run,
        int $tenantId,
        array $options = [],
    ): void {
        $dryRun = (bool) ($options['dry_run'] ?? false);

        // Pre-build supplier name → id map for this tenant
        $supplierMap = Supplier::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'name')
            ->all();

        $errors = [];
        $validRows = [];
        $rowNum = 0;

        foreach ($source->fetch('skus') as $raw) {
            $rowNum++;
            $row = $source->transform('skus', $raw);
            $rowErrors = $this->validate($row, $supplierMap);

            if ($rowErrors) {
                $errors[] = ['row' => $rowNum, 'data' => $raw, 'errors' => $rowErrors];
            } else {
                $validRows[] = array_merge($row, [
                    'supplier_id' => $supplierMap[$row['supplier_name']],
                ]);
            }
        }

        $succeeded = 0;

        if (! $dryRun && $validRows) {
            DB::transaction(function () use ($validRows, $tenantId, &$succeeded) {
                foreach ($validRows as $row) {
                    Sku::withoutGlobalScopes()->updateOrCreate(
                        ['tenant_id' => $tenantId, 'sku_code' => $row['sku_code']],
                        [
                            'tenant_id'      => $tenantId,
                            'name'           => $row['name'],
                            'category'       => $row['category'],
                            'supplier_id'    => $row['supplier_id'],
                            'moq'            => $row['moq'],
                            'unit_cost'      => $row['unit_cost'],
                            'current_stock'  => $row['current_stock'],
                            'lead_time_days' => $row['lead_time_days'],
                            'reorder_qty'    => $row['moq'], // default to MOQ
                        ],
                    );
                    $succeeded++;
                }
            });
        }

        $failed = count($errors);
        $status = match (true) {
            $failed > 0 && $succeeded > 0 => DataIngestionRun::STATUS_PARTIAL,
            $failed > 0 && $succeeded === 0 => DataIngestionRun::STATUS_FAILED,
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

    private function validate(array $row, array $supplierMap): array
    {
        $errors = [];

        if ($row['sku_code'] === '') {
            $errors[] = 'sku_code is required';
        }

        if ($row['name'] === '') {
            $errors[] = 'name is required';
        }

        if (! in_array($row['category'], self::VALID_CATEGORIES, true)) {
            $errors[] = "category must be one of: " . implode(', ', self::VALID_CATEGORIES);
        }

        if ($row['supplier_name'] === '') {
            $errors[] = 'supplier_name is required';
        } elseif (! array_key_exists($row['supplier_name'], $supplierMap)) {
            $errors[] = "supplier_name '{$row['supplier_name']}' not found — import suppliers first";
        }

        if ($row['moq'] <= 0) {
            $errors[] = 'moq must be a positive integer';
        }

        if ($row['unit_cost'] <= 0) {
            $errors[] = 'unit_cost must be a positive number';
        }

        if ($row['current_stock'] < 0) {
            $errors[] = 'current_stock must be non-negative';
        }

        if ($row['lead_time_days'] <= 0) {
            $errors[] = 'lead_time_days must be a positive integer';
        }

        return $errors;
    }
}
