<?php

namespace App\Console\Commands;

use App\Models\DataIngestionRun;
use App\Services\Ingestion\DataIngestionService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class IngestionCsvCommand extends Command
{
    protected $signature = 'ingestion:csv
                            {entity : Entity to import (skus|suppliers|sales_history)}
                            {path   : Absolute path to the CSV file}
                            {--tenant=1 : Tenant ID}
                            {--dry-run  : Validate without writing to the database}';

    protected $description = 'Import a CSV file for a specific entity (skus, suppliers, sales_history)';

    public function handle(DataIngestionService $service): int
    {
        $entity   = $this->argument('entity');
        $path     = $this->argument('path');
        $tenantId = (int) $this->option('tenant');
        $dryRun   = (bool) $this->option('dry-run');

        if (! in_array($entity, ['skus', 'suppliers', 'sales_history'], true)) {
            $this->error("Invalid entity '{$entity}'. Must be one of: skus, suppliers, sales_history");
            return self::FAILURE;
        }

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run — validating without writing.');
        }

        $run = TenantContext::run(
            $tenantId,
            fn () => $service->importCsv($entity, $path, $tenantId, ['dry_run' => $dryRun]),
        );

        $this->line("Status:          {$run->status}");
        $this->line("Rows processed:  {$run->rows_processed}");
        $this->line("Rows succeeded:  {$run->rows_succeeded}");
        $this->line("Rows failed:     {$run->rows_failed}");

        if ($run->error_log) {
            $this->newLine();
            $this->warn('Validation errors:');
            foreach ($run->error_log as $err) {
                $rowLabel = isset($err['row']) ? "Row {$err['row']}" : 'Error';
                $msgs     = isset($err['errors']) ? implode(', ', $err['errors']) : ($err['error'] ?? 'unknown');
                $this->line("  {$rowLabel}: {$msgs}");
            }
        }

        return $run->status === DataIngestionRun::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
