<?php

namespace App\Services\Ingestion;

use App\Models\DataIngestionRun;
use App\Services\Ingestion\Importers\SalesHistoryImporter;
use App\Services\Ingestion\Importers\SkuImporter;
use App\Services\Ingestion\Importers\SupplierImporter;

class DataIngestionService
{
    public function __construct(
        private readonly SupplierImporter     $supplierImporter,
        private readonly SkuImporter          $skuImporter,
        private readonly SalesHistoryImporter $salesHistoryImporter,
    ) {}

    /**
     * Run a CSV import for the given entity.
     * Creates a DataIngestionRun, delegates to the matching importer, and returns the final run.
     */
    public function importCsv(
        string $entity,
        string $filePath,
        int $tenantId,
        array $options = [],
    ): DataIngestionRun {
        $run = DataIngestionRun::withoutGlobalScopes()->create([
            'tenant_id'  => $tenantId,
            'source'     => DataIngestionRun::SOURCE_CSV_UPLOAD,
            'importer'   => $this->importerName($entity),
            'status'     => DataIngestionRun::STATUS_RUNNING,
            'started_at' => now(),
            'metadata'   => array_filter([
                'entity'   => $entity,
                'file'     => basename($filePath),
                'dry_run'  => ($options['dry_run'] ?? false) ?: null,
            ]),
        ]);

        $source = new Sources\CsvSource($filePath);

        try {
            $importer = match ($entity) {
                'suppliers'     => $this->supplierImporter,
                'skus'          => $this->skuImporter,
                'sales_history' => $this->salesHistoryImporter,
                default         => throw new \InvalidArgumentException("Unknown entity: {$entity}"),
            };

            $importer->import($source, $run, $tenantId, $options);
        } catch (\Throwable $e) {
            $run->update([
                'status'       => DataIngestionRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_log'    => [['error' => $e->getMessage()]],
            ]);
        }

        return $run->fresh();
    }

    private function importerName(string $entity): string
    {
        return match ($entity) {
            'suppliers'     => 'SupplierImporter',
            'skus'          => 'SkuImporter',
            'sales_history' => 'SalesHistoryImporter',
            default         => $entity,
        };
    }
}
