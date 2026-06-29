<?php

namespace App\Jobs;

use App\Models\DataIngestionRun;
use App\Models\IngestionCredential;
use App\Models\Sku;
use App\Services\Ingestion\Importers\SalesHistoryImporter;
use App\Services\Ingestion\Sources\ShopifySource;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunShopifyInitialLoadJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries   = 2;

    public function __construct(
        public readonly int $credentialId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('forecasting');
    }

    public function handle(): void
    {
        TenantContext::run($this->tenantId, fn () => $this->runForTenant());
    }

    private function runForTenant(): void
    {
        $credential = IngestionCredential::withoutGlobalScopes()->findOrFail($this->credentialId);

        if (! $credential->is_active) {
            return;
        }

        $creds  = $credential->credentials;
        $cursor = Carbon::now()->subMonths(24)->toIso8601String();
        $source = new ShopifySource($creds['shop_domain'], $creds['access_token'], $cursor);

        // ── 1. Sync current stock for existing SKUs ───────────────────────────
        $this->syncStock($source, $credential);

        // ── 2. Pull 24 months of orders through SalesHistoryImporter ─────────
        $this->syncSalesHistory($source, $credential);

        $credential->update([
            'last_sync_at'    => now(),
            'last_sync_cursor' => now()->toIso8601String(),
        ]);
    }

    private function syncStock(ShopifySource $source, IngestionCredential $credential): void
    {
        $succeeded = 0;
        $failed    = 0;
        $errors    = [];
        $rowNum    = 0;

        $run = DataIngestionRun::withoutGlobalScopes()->create([
            'tenant_id'  => $this->tenantId,
            'source'     => 'shopify',
            'importer'   => 'ShopifyStockSync',
            'status'     => DataIngestionRun::STATUS_RUNNING,
            'started_at' => now(),
            'metadata'   => ['entity' => 'skus'],
        ]);

        try {
            foreach ($source->fetch('skus') as $raw) {
                $rowNum++;
                $variant = $source->transform('skus', $raw);
                $skuCode = $variant['sku_code'];

                if (empty($skuCode)) {
                    $failed++;
                    continue;
                }

                $updated = Sku::withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)
                    ->where('sku_code', $skuCode)
                    ->update(['current_stock' => max(0, $variant['current_stock'])]);

                if ($updated === 0) {
                    $failed++;
                    $errors[] = [
                        'row'    => $rowNum,
                        'errors' => ["{$skuCode}: not found — import via CSV first"],
                    ];
                } else {
                    $succeeded++;
                }
            }

            $status = match (true) {
                $failed > 0 && $succeeded > 0  => DataIngestionRun::STATUS_PARTIAL,
                $failed > 0 && $succeeded === 0 => DataIngestionRun::STATUS_FAILED,
                default                         => DataIngestionRun::STATUS_COMPLETED,
            };

            $run->update([
                'status'         => $status,
                'rows_processed' => $rowNum,
                'rows_succeeded' => $succeeded,
                'rows_failed'    => $failed,
                'error_log'      => $errors ?: null,
                'completed_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status'       => DataIngestionRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_log'    => [['error' => $e->getMessage()]],
            ]);
        }
    }

    private function syncSalesHistory(ShopifySource $source, IngestionCredential $credential): void
    {
        $run = DataIngestionRun::withoutGlobalScopes()->create([
            'tenant_id'  => $this->tenantId,
            'source'     => 'shopify',
            'importer'   => 'SalesHistoryImporter',
            'status'     => DataIngestionRun::STATUS_RUNNING,
            'started_at' => now(),
            'metadata'   => ['entity' => 'sales_history'],
        ]);

        try {
            (new SalesHistoryImporter())->import($source, $run, $this->tenantId);
        } catch (\Throwable $e) {
            $run->update([
                'status'       => DataIngestionRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_log'    => [['error' => $e->getMessage()]],
            ]);
        }
    }
}
