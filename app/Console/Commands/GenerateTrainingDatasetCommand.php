<?php

namespace App\Console\Commands;

use App\Support\TenantContext;
use Illuminate\Console\Command;
use Tests\Fixtures\ShopifyFixtureGenerator;

/**
 * Generate the 3-year synthetic training dataset that Chunk 3's calibration
 * job fits on. Produces:
 *   - Shopify-shape sales_history (via ShopifyOrderFactory pathology mix)
 *   - lead_time_observations (one per replenishment arrival)
 *   - stockout_events (one per stockout episode)
 *   - inventory_position_snapshots (daily, per SKU)
 *
 * Run pre-delivery to seed cold-start ground truth. Once a real client is
 * connected, this command is unused — production data populates the same
 * tables incrementally.
 *
 * Usage:
 *   php artisan training:generate
 *   php artisan training:generate --skus=30 --years=3 --seed=42
 */
class GenerateTrainingDatasetCommand extends Command
{
    protected $signature = 'training:generate
        {--skus=30      : Number of SKUs to generate}
        {--years=3      : Years of history}
        {--seed=42      : RNG seed for deterministic output}
        {--tenant=1     : Tenant ID to seed under}';

    protected $description = 'Generate 3-year training dataset (sales + lead times + stockouts + snapshots) for calibration';

    public function handle(): int
    {
        if (! class_exists(ShopifyFixtureGenerator::class)) {
            $this->error('Tests\\Fixtures\\ShopifyFixtureGenerator not autoloaded — run composer dump-autoload.');
            return self::FAILURE;
        }

        $skus     = (int) $this->option('skus');
        $years    = (int) $this->option('years');
        $seed     = (int) $this->option('seed');
        $tenantId = (int) $this->option('tenant');
        $days     = $years * 365;

        return TenantContext::run($tenantId, fn () => $this->generateForTenant($skus, $years, $days, $seed, $tenantId));
    }

    private function generateForTenant(int $skus, int $years, int $days, int $seed, int $tenantId): int
    {
        $this->info("Generating training dataset:");
        $this->line("  SKUs:    {$skus}");
        $this->line("  Years:   {$years} ({$days} days)");
        $this->line("  Seed:    {$seed}");
        $this->line("  Tenant:  {$tenantId}");
        $this->newLine();

        $started = microtime(true);

        $generator = new ShopifyFixtureGenerator($seed, $tenantId);
        $summary   = $generator->generate(
            skuCount:     $skus,
            historyDays:  $days,
            simulate:     true,
        );

        $elapsed = round(microtime(true) - $started, 1);

        // Aggregates
        $totalSales      = array_sum(array_column($summary, 'rows_written'));
        $totalSnapshots  = 0;
        $totalLeadTimes  = 0;
        $totalStockouts  = 0;
        foreach ($summary as $entry) {
            if (isset($entry['simulation'])) {
                $totalSnapshots += $entry['simulation']['snapshots'];
                $totalLeadTimes += $entry['simulation']['lead_times'];
                $totalStockouts += $entry['simulation']['stockouts'];
            }
        }

        $this->newLine();
        $this->info("Generated in {$elapsed}s:");
        $this->line("  sales_history:                {$totalSales}");
        $this->line("  inventory_position_snapshots: {$totalSnapshots}");
        $this->line("  lead_time_observations:       {$totalLeadTimes}");
        $this->line("  stockout_events:              {$totalStockouts}");
        $this->newLine();
        $this->info('Ready for Chunk 3 calibration.');

        return self::SUCCESS;
    }
}
