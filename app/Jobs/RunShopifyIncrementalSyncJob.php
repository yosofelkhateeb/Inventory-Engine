<?php

namespace App\Jobs;

use App\Models\DataIngestionRun;
use App\Models\IngestionCredential;
use App\Services\Ingestion\Importers\SalesHistoryImporter;
use App\Services\Ingestion\Sources\ShopifySource;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunShopifyIncrementalSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $credentialId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $credential = IngestionCredential::withoutGlobalScopes()->findOrFail($this->credentialId);

        if (! $credential->is_active) {
            return;
        }

        TenantContext::run($credential->tenant_id, fn () => $this->runForCredential($credential));
    }

    private function runForCredential(IngestionCredential $credential): void
    {
        $creds       = $credential->credentials;
        $cursor      = $credential->last_sync_cursor
            ?? Carbon::now()->subDays(7)->toIso8601String();

        $source = new ShopifySource($creds['shop_domain'], $creds['access_token'], $cursor);

        $run = DataIngestionRun::withoutGlobalScopes()->create([
            'tenant_id'  => $credential->tenant_id,
            'source'     => 'shopify',
            'importer'   => 'SalesHistoryImporter',
            'status'     => DataIngestionRun::STATUS_RUNNING,
            'started_at' => now(),
            'metadata'   => [
                'entity'         => 'sales_history',
                'cursor_before'  => $cursor,
            ],
        ]);

        try {
            (new SalesHistoryImporter())->import($source, $run, $credential->tenant_id);

            $credential->update([
                'last_sync_at'    => now(),
                'last_sync_cursor' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status'       => DataIngestionRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_log'    => [['error' => $e->getMessage()]],
            ]);

            throw $e;
        }
    }
}
