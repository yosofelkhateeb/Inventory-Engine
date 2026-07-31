<?php

namespace App\Console\Commands;

use App\Services\Synthetic\SeaDemoFeedTopUp;
use Illuminate\Console\Command;

/**
 * Appends elapsed days to the demo's synthetic sales feed so the hosted
 * dataset does not age. See SeaDemoFeedTopUp for the mechanics and for why
 * stopped-selling SKUs are deliberately left alone.
 *
 * Disabled by default. Enable only where the data is synthetic:
 *   DEMO_FEED_TOPUP_ENABLED=true
 */
class DemoTopUpSalesCommand extends Command
{
    protected $signature = 'demo:topup-sales
        {--tenant= : Tenant ID to top up (defaults to the synthetic dataset tenant)}
        {--through= : Last date to generate (Y-m-d). Defaults to yesterday.}
        {--force : Run even when the config flag is disabled}';

    protected $description = 'Append synthetic sales rows for elapsed days so the demo feed stays current';

    public function handle(): int
    {
        if (! config('synthetic_dataset.feed_topup.enabled') && ! $this->option('force')) {
            $this->warn('Demo feed top-up is disabled. Set DEMO_FEED_TOPUP_ENABLED=true, or pass --force.');
            $this->line('This command writes synthetic sales rows and must never run against real data.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant') !== null
            ? (int) $this->option('tenant')
            : (int) config('synthetic_dataset.tenant_id', 1);

        $through = $this->option('through')
            ? \Carbon\Carbon::parse($this->option('through'))
            : null;

        $result = (new SeaDemoFeedTopUp(
            seed:     (int) config('synthetic_dataset.rng_seed', 42),
            tenantId: $tenantId,
        ))->run($through);

        $this->info("Demo feed topped up through {$result['through']} (tenant {$tenantId}).");
        $this->table(
            ['Topped up', 'Already current', 'Frozen (stopped_selling)', 'Skipped (no history)', 'Rows written'],
            [[
                $result['skus_topped_up'],
                $result['skus_already_current'],
                $result['skus_frozen'],
                $result['skus_missing'],
                $result['rows_written'],
            ]],
        );

        if ($result['rows_written'] > 0) {
            $this->line('Forecasts are now behind the feed. Run forecast:sweep to refresh them.');
        }

        return self::SUCCESS;
    }
}
