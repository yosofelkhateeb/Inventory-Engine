<?php

namespace App\Console\Commands;

use App\Jobs\RunForecastJob;
use App\Models\Sku;
use Illuminate\Console\Command;

class ForecastSweepCommand extends Command
{
    protected $signature = 'forecast:sweep
        {--tenant= : Tenant ID to sweep (defaults to all tenants)}';

    protected $description = 'Dispatch RunForecastJob for every active SKU in a tenant (monthly safety-net sweep)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $query = Sku::withoutGlobalScopes()->whereNull('deleted_at');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $skus = $query->get(['id', 'tenant_id', 'sku_code']);

        if ($skus->isEmpty()) {
            $this->info('No active SKUs found.');
            return self::SUCCESS;
        }

        foreach ($skus as $sku) {
            RunForecastJob::dispatch($sku->id, $sku->tenant_id, 'scheduled');
        }

        $this->info("Dispatched {$skus->count()} forecast jobs.");

        return self::SUCCESS;
    }
}
