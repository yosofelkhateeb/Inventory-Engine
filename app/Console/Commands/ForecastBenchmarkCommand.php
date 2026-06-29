<?php

namespace App\Console\Commands;

use App\Jobs\RunForecastJob;
use App\Models\ForecastModelRegistry;
use App\Models\SalesHistory;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Tests\Fixtures\ShopifyFixtureGenerator;

/**
 * Benchmarks the forecasting pipeline against a realistic Shopify-shaped
 * fixture. Intended to be run manually before releases; writes a Markdown
 * report for human review rather than asserting pass/fail.
 *
 * Usage:
 *   php artisan forecast:benchmark --skus=30 --seed=42 --history-days=365
 *
 * Output: storage/app/forecasting/benchmarks/benchmark_<timestamp>.md
 */
class ForecastBenchmarkCommand extends Command
{
    protected $signature = 'forecast:benchmark
        {--skus=30            : Number of SKUs to generate}
        {--seed=42            : RNG seed for deterministic output}
        {--history-days=365   : Days of history per SKU}
        {--tenant=1           : Tenant ID to seed under}
        {--skip-fixture       : Reuse existing sales_history (don\'t regenerate)}';

    protected $description = 'Run the forecasting pipeline against a Shopify-shaped fixture and produce a benchmark report';

    public function handle(): int
    {
        if (! class_exists(ShopifyFixtureGenerator::class)) {
            $this->error('Tests\Fixtures\ShopifyFixtureGenerator not autoloaded — run composer dump-autoload or install with dev dependencies.');
            return self::FAILURE;
        }

        $skus        = (int) $this->option('skus');
        $seed        = (int) $this->option('seed');
        $historyDays = (int) $this->option('history-days');
        $tenantId    = (int) $this->option('tenant');

        return TenantContext::run($tenantId, fn () => $this->runBenchmark($skus, $seed, $historyDays, $tenantId));
    }

    private function runBenchmark(int $skus, int $seed, int $historyDays, int $tenantId): int
    {

        $this->info("Benchmarking pipeline: {$skus} SKUs, seed={$seed}, history={$historyDays}d, tenant={$tenantId}");

        $assignments = [];

        if (! $this->option('skip-fixture')) {
            $this->info('Generating Shopify-shaped fixture…');
            $generator   = new ShopifyFixtureGenerator($seed, $tenantId);
            $assignments = $generator->generate($skus, $historyDays);
            $this->info('  Fixture written: ' . count($assignments) . ' SKUs');
        } else {
            $this->warn('Skipping fixture generation (--skip-fixture).');
        }

        $this->info('Running pipeline for each SKU (this takes a few minutes per SKU)…');
        $results = [];

        foreach ($assignments as $idx => $a) {
            $this->line(sprintf('  [%d/%d] %s (%s)…', $idx + 1, count($assignments), $a['sku_code'], $a['pathology']));

            $startedAt = microtime(true);
            try {
                (new RunForecastJob($a['sku_id'], $tenantId, 'manual'))->handle();
                $failed  = false;
                $message = null;
            } catch (\Throwable $e) {
                $failed  = true;
                $message = $e->getMessage();
            }
            $elapsed = microtime(true) - $startedAt;

            $reg = ForecastModelRegistry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sku_id', $a['sku_id'])
                ->first();

            $results[] = [
                'sku_code'    => $a['sku_code'],
                'pathology'   => $a['pathology'],
                'rows'        => $a['rows_written'],
                'model'       => $reg?->model_name,
                'demand_rate' => $reg?->demand_rate !== null ? (float) $reg->demand_rate : null,
                'smape'       => $reg?->smape !== null ? (float) $reg->smape : null,
                'mae'         => $reg?->mae !== null ? (float) $reg->mae : null,
                'warnings'    => $reg?->warnings ?? [],
                'runtime_sec' => round($elapsed, 1),
                'failed'      => $failed,
                'error'       => $message,
            ];
        }

        $this->writeReport($results, $seed, $historyDays, $tenantId);

        return self::SUCCESS;
    }

    private function writeReport(array $results, int $seed, int $historyDays, int $tenantId): void
    {
        $dir = storage_path('app/forecasting/benchmarks');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Ymd_His');
        $path      = "{$dir}/benchmark_{$timestamp}.md";

        // Portfolio WMAPE across all completed SKUs
        $totalActual = 0.0;
        $totalError  = 0.0;
        $since       = Carbon::today()->subDays(30);

        foreach ($results as $r) {
            if ($r['failed'] || $r['demand_rate'] === null) {
                continue;
            }
            $sku = \App\Models\Sku::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sku_code', $r['sku_code'])
                ->first();
            if (! $sku) {
                continue;
            }
            $actualAvg = (float) (SalesHistory::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sku_id', $sku->id)
                ->where('sale_date', '>=', $since)
                ->avg('quantity_sold') ?? 0.0);
            $totalActual += abs($actualAvg);
            $totalError  += abs($r['demand_rate'] - $actualAvg);
        }
        $portfolioWmape = $totalActual > 0 ? round(($totalError / $totalActual) * 100, 2) : null;

        // Pathology-level rollup
        $byPathology = [];
        foreach ($results as $r) {
            $byPathology[$r['pathology']][] = $r;
        }

        $lines = [];
        $lines[] = "# Forecast Benchmark Report";
        $lines[] = "";
        $lines[] = "- Generated: " . Carbon::now()->toIso8601String();
        $lines[] = "- Seed: `{$seed}`";
        $lines[] = "- History days: {$historyDays}";
        $lines[] = "- Tenant: {$tenantId}";
        $lines[] = "- SKUs: " . count($results);
        $lines[] = "- **Portfolio WMAPE**: " . ($portfolioWmape !== null ? "{$portfolioWmape}%" : 'n/a');
        $lines[] = "";

        $lines[] = "## By pathology";
        $lines[] = "";
        $lines[] = "| Pathology | SKUs | Median sMAPE | Mean runtime (s) | Warnings fired |";
        $lines[] = "|---|---:|---:|---:|---:|";
        foreach ($byPathology as $pathology => $rows) {
            $smapes   = array_filter(array_column($rows, 'smape'), fn ($x) => $x !== null);
            $runtimes = array_column($rows, 'runtime_sec');
            $warnHit  = count(array_filter($rows, fn ($r) => ! empty($r['warnings'])));
            $medSmape = $smapes ? round($this->median($smapes), 1) . '%' : '—';
            $meanRt   = $runtimes ? round(array_sum($runtimes) / count($runtimes), 1) : '—';
            $lines[]  = "| {$pathology} | " . count($rows) . " | {$medSmape} | {$meanRt} | {$warnHit} |";
        }
        $lines[] = "";

        $lines[] = "## Per-SKU detail";
        $lines[] = "";
        $lines[] = "| SKU | Pathology | Rows | Model | Demand rate | sMAPE | MAE | Runtime (s) | Warnings |";
        $lines[] = "|---|---|---:|---|---:|---:|---:|---:|---|";
        foreach ($results as $r) {
            if ($r['failed']) {
                $lines[] = "| {$r['sku_code']} | {$r['pathology']} | {$r['rows']} | **FAILED** | — | — | — | {$r['runtime_sec']} | `" . str_replace('|', '/', (string) $r['error']) . "` |";
                continue;
            }
            $warnLabels = collect($r['warnings'])
                ->map(fn ($w) => is_string($w) ? explode(':', $w, 2)[0] : 'unknown')
                ->unique()
                ->implode(', ');
            $demand = $r['demand_rate'] !== null ? number_format($r['demand_rate'], 2) : '—';
            $smape  = $r['smape']       !== null ? number_format($r['smape'], 1) . '%' : '—';
            $mae    = $r['mae']         !== null ? number_format($r['mae'], 2) : '—';
            $lines[] = "| {$r['sku_code']} | {$r['pathology']} | {$r['rows']} | {$r['model']} | {$demand} | {$smape} | {$mae} | {$r['runtime_sec']} | {$warnLabels} |";
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->info("Report written: {$path}");

        if ($portfolioWmape !== null) {
            $this->info("Portfolio WMAPE: {$portfolioWmape}%");
        }
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid   = (int) floor(($count - 1) / 2);

        return $count % 2 === 0
            ? ($values[$mid] + $values[$mid + 1]) / 2
            : $values[$mid];
    }
}
