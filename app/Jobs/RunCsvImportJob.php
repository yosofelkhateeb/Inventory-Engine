<?php

namespace App\Jobs;

use App\Services\Ingestion\DataIngestionService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunCsvImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $entity,
        private readonly string $filePath,
        private readonly int $tenantId,
        private readonly array $options = [],
    ) {}

    public function handle(DataIngestionService $service): void
    {
        TenantContext::run($this->tenantId, function () use ($service) {
            try {
                $service->importCsv($this->entity, $this->filePath, $this->tenantId, $this->options);
            } finally {
                if (file_exists($this->filePath)) {
                    unlink($this->filePath);
                }
            }
        });
    }
}
