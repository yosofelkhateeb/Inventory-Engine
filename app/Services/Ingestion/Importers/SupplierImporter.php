<?php

namespace App\Services\Ingestion\Importers;

use App\Models\DataIngestionRun;
use App\Models\Supplier;
use App\Services\Ingestion\IngestionSource;
use Illuminate\Support\Facades\DB;

class SupplierImporter
{
    public function import(
        IngestionSource $source,
        DataIngestionRun $run,
        int $tenantId,
        array $options = [],
    ): void {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $errors = [];
        $validRows = [];
        $rowNum = 0;

        foreach ($source->fetch('suppliers') as $raw) {
            $rowNum++;
            $row = $source->transform('suppliers', $raw);
            $rowErrors = $this->validate($row);

            if ($rowErrors) {
                $errors[] = ['row' => $rowNum, 'data' => $raw, 'errors' => $rowErrors];
            } else {
                $validRows[] = $row;
            }
        }

        $succeeded = 0;

        if (! $dryRun && $validRows) {
            DB::transaction(function () use ($validRows, $tenantId, &$succeeded) {
                foreach ($validRows as $row) {
                    Supplier::withoutGlobalScopes()->updateOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $row['name']],
                        ['tenant_id' => $tenantId, 'avg_lead_time_days' => $row['avg_lead_time_days']],
                    );
                    $succeeded++;
                }
            });
        } elseif ($dryRun) {
            $succeeded = 0;
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

    private function validate(array $row): array
    {
        $errors = [];

        if ($row['name'] === '') {
            $errors[] = 'name is required';
        }

        if (! is_numeric($row['avg_lead_time_days']) || $row['avg_lead_time_days'] < 0) {
            $errors[] = 'stated_lead_time_days must be a non-negative integer';
        }

        return $errors;
    }
}
