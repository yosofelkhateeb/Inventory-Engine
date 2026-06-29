<?php

namespace App\Jobs;

use App\Models\ForecastModelRegistry;
use App\Models\Promotion;
use App\Models\RegionalHoliday;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Models\SkuDemandProfile;
use App\Models\SystemSetting;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;

class RunForecastJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const VALID_TRIGGERS = ['scheduled', 'new_sku', 'bias_drift', 'manual'];

    private const REQUIRED_OUTPUT_KEYS = ['model_name', 'demand_rate', 'reeval_trigger', 'trained_at', 'next_review_at'];

    public function __construct(
        public readonly int    $skuId,
        public readonly int    $tenantId,
        public readonly string $reevalTrigger = 'scheduled',
    ) {
        if (! in_array($this->reevalTrigger, self::VALID_TRIGGERS, true)) {
            throw new \InvalidArgumentException("Invalid reeval_trigger: {$this->reevalTrigger}");
        }

        $this->onQueue('forecasting');
    }

    public function handle(): void
    {
        TenantContext::run($this->tenantId, fn () => $this->runForTenant());
    }

    private function runForTenant(): void
    {
        /** @var Sku $sku */
        $sku = Sku::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->findOrFail($this->skuId);

        $category = $this->normaliseCategory($sku->category ?? 'accessory');

        // ── 1. Load thresholds from system_settings ──────────────────────────
        $thresholds = [
            'min_history_days'       => (int) SystemSetting::get(
                $this->tenantId,
                "forecast.{$category}.min_history_days",
                90,
            ),
            'seasonality_min_days'   => (int) SystemSetting::get(
                $this->tenantId,
                "forecast.{$category}.seasonality_min_days",
                365,
            ),
            'intermittency_threshold' => (float) SystemSetting::get(
                $this->tenantId,
                "forecast.{$category}.intermittency_threshold",
                0.20,
            ),
        ];

        // ── 2. Load sales history ─────────────────────────────────────────────
        $salesRows = SalesHistory::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('sku_id', $this->skuId)
            ->orderBy('sale_date')
            ->get(['sale_date', 'quantity_sold', 'is_promotion']);

        $salesHistory = $salesRows->map(fn ($r) => [
            'date'          => Carbon::parse($r->sale_date)->format('Y-m-d'),
            'quantity_sold' => (int) $r->quantity_sold,
            'is_promotion'  => (bool) $r->is_promotion,
        ])->values()->all();

        // ── 3. Load active promotions ─────────────────────────────────────────
        $promotions = Promotion::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where(fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('end_date', '>=', Carbon::today())
            )
            ->get(['name', 'start_date', 'end_date', 'expected_uplift_pct'])
            ->map(fn ($p) => [
                'start_date' => Carbon::parse($p->start_date)->format('Y-m-d'),
                'end_date'   => Carbon::parse($p->end_date)->format('Y-m-d'),
                'uplift_pct' => (float) $p->expected_uplift_pct,
            ])->values()->all();

        // ── 4. Load regional holidays for current + next year ─────────────────
        $countryCode = env('CLIENT_COUNTRY_CODE', 'MY');
        $years       = [Carbon::today()->year, Carbon::today()->year + 1];

        $holidays = RegionalHoliday::withoutGlobalScopes()
            ->where('country_code', $countryCode)
            ->whereIn('year', $years)
            ->get(['holiday_date', 'holiday_name', 'default_uplift_pct'])
            ->map(fn ($h) => [
                'date'       => Carbon::parse($h->holiday_date)->format('Y-m-d'),
                'name'       => $h->holiday_name,
                'uplift_pct' => (float) $h->default_uplift_pct,
            ])->values()->all();

        // ── 5. Write input JSON to temp file ──────────────────────────────────
        $inputData = [
            'sku_id'            => $this->skuId,
            'tenant_id'         => $this->tenantId,
            'sku_category'      => $category,
            'reeval_trigger'    => $this->reevalTrigger,
            'thresholds'        => $thresholds,
            'sales_history'     => $salesHistory,
            'promotions'        => $promotions,
            'regional_holidays' => $holidays,
        ];

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . "forecast_input_{$this->skuId}_{$this->tenantId}.json";

        file_put_contents($tmpPath, json_encode($inputData));

        // ── 6. Call Python microservice ───────────────────────────────────────
        $pythonBin    = config('forecasting.python_bin', 'python');
        $scriptPath   = base_path('python/forecasting/main.py');

        $result = Process::path(base_path())
            ->timeout(config('forecasting.process_timeout', 600))
            ->run([$pythonBin, $scriptPath, '--input', $tmpPath]);

        @unlink($tmpPath);

        if (! $result->successful()) {
            logger()->error('RunForecastJob: Python process failed', [
                'sku_id'    => $this->skuId,
                'tenant_id' => $this->tenantId,
                'stderr'    => $result->errorOutput(),
                'stdout'    => $result->output(),
            ]);
            throw new \RuntimeException(
                "Python forecasting process exited with code {$result->exitCode()}"
            );
        }

        // ── 7. Parse JSON output ──────────────────────────────────────────────
        $output = json_decode(trim($result->output()), true);

        if (! is_array($output) || isset($output['error'])) {
            $msg = $output['error'] ?? 'Invalid JSON from Python process';
            logger()->error("RunForecastJob: {$msg}", [
                'sku_id'    => $this->skuId,
                'tenant_id' => $this->tenantId,
            ]);
            throw new \RuntimeException($msg);
        }

        $missing = array_diff(self::REQUIRED_OUTPUT_KEYS, array_keys($output));
        if ($missing) {
            throw new \RuntimeException('Python output missing required fields: ' . implode(', ', $missing));
        }

        // ── 8. Upsert forecast_model_registry ────────────────────────────────
        ForecastModelRegistry::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenantId, 'sku_id' => $this->skuId],
            [
                'model_name'                    => $output['model_name'],
                'demand_rate'                   => $output['demand_rate'],
                'forecast_horizon_days'         => $output['forecast_horizon_days'] ?? 30,
                'mae'                           => $output['mae'] ?? null,
                'rmse'                          => $output['rmse'] ?? null,
                'bias'                          => $output['bias'] ?? null,
                'smape'                         => $output['smape'] ?? null,
                'reeval_trigger'                => $output['reeval_trigger'],
                'warnings'                      => $output['warnings'] ?? [],
                'trained_at'                    => $output['trained_at'],
                'next_review_at'                => $output['next_review_at'],
                'interval_lower'                => $output['interval_lower'] ?? null,
                'interval_upper'                => $output['interval_upper'] ?? null,
                'interval_confidence'           => $output['interval_confidence'] ?? null,
                'interval_empirical_coverage'   => $output['interval_empirical_coverage'] ?? null,
                'selection_rationale'           => $output['selection_rationale'] ?? null,
                'transformation_applied'        => $output['transformation_applied'] ?? 'none',
                'hyperparameters'               => $output['hyperparameters'] ?? [],
            ],
        );

        // ── 9. Upsert sku_demand_profiles ─────────────────────────────────────
        $profile = $output['demand_profile'] ?? [];

        if (! empty($profile)) {
            SkuDemandProfile::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $this->tenantId, 'sku_id' => $this->skuId],
                [
                    'volume_tier'          => $profile['volume_tier']          ?? 'low',
                    'volatility'           => $profile['volatility']           ?? 'stable',
                    'intermittency'        => $profile['intermittency']        ?? 'continuous',
                    'seasonality_detected' => $profile['seasonality_detected'] ?? false,
                    'trend_direction'      => $profile['trend_direction']      ?? 'flat',
                    'history_days_used'    => $profile['history_days_used']    ?? 0,
                ],
            );
        }
    }

    private function normaliseCategory(string $category): string
    {
        return match (strtolower($category)) {
            'equipment', 'equipments' => 'equipment',
            'bundle', 'bundles'   => 'bundle',
            default               => 'accessory',
        };
    }
}
