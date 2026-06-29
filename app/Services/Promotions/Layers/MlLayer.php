<?php

namespace App\Services\Promotions\Layers;

use App\Models\Promotion;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Services\Promotions\BriefVectorizer;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Layer 3 of the Layered Hybrid Uplift Prediction Engine — LightGBM
 * quantile regression. Activates when the orchestrator detects enough
 * tagged history (uplift.min_ml_samples; default 50).
 *
 * Architecture
 * ------------
 * PHP prepares the training corpus from the database (past Brief-tagged
 * promotions with computable actual lifts, vectorized via the shared
 * BriefVectorizer) and writes everything to a temp JSON file. Then it
 * invokes python/forecasting/uplift/main.py as a subprocess. Python
 * fits three quantile regressors (q=0.25/0.5/0.75), predicts, and
 * emits a single-line JSON result on stdout.
 *
 * This mirrors the existing forecasting microservice pattern (RunForecastJob
 * → python/forecasting/main.py): Python doesn't touch the database; it
 * receives data in, returns prediction out.
 *
 * Failure modes — all graceful, all return sample_size=0 for the
 * orchestrator to fall back to Layer 2:
 *   - Python binary missing
 *   - LightGBM not installed
 *   - Training set below MIN_TRAINING_SAMPLES (handled in Python)
 *   - Subprocess timeout
 *   - Subprocess non-zero exit
 *   - Malformed JSON output
 *
 * Output shape matches every layer's contract:
 *   {value, lower, upper, basis, sample_size, layer}
 */
class MlLayer
{
    public const LAYER_NAME = 'ml';

    private const LOOKBACK_DAYS = 730;        // 24 months — same as Layer 2
    private const BASELINE_WINDOW_DAYS = 30;
    private const SUBPROCESS_TIMEOUT_S = 120; // LightGBM trains fast on small data

    public function __construct(private readonly BriefVectorizer $vectorizer) {}

    /**
     * @param array{
     *   promotion_type?: ?string,
     *   discount_pct?: ?float,
     *   discount_type?: ?string,
     *   channel_mix?: ?array<string>,
     *   ad_spend_band?: ?string,
     *   audience?: ?string,
     *   lead_announcement_days?: ?int,
     * } $brief
     */
    public function predict(array $brief): array
    {
        $candidates = $this->findCandidatePromotions();
        $trainingData = $this->buildTrainingData($candidates);

        if (empty($trainingData)) {
            return $this->emptyResult('No tagged campaigns with computable lift in the lookback window.');
        }

        // Pass the tenant's configured min_ml_samples to Python so the same
        // threshold gates both sides (PHP orchestrator + Python pre-check).
        $minSamples = (int) \App\Models\SystemSetting::get(
            TenantContext::tenantId(),
            'uplift.min_ml_samples',
            50,
        );

        $payload = [
            'brief'         => ['features' => $this->vectorizer->vectorize($brief)],
            'training_data' => $trainingData,
            'min_samples'   => $minSamples,
        ];

        return $this->invokePython($payload);
    }

    // ─── Training data assembly ────────────────────────────────────────────

    private function findCandidatePromotions(): Collection
    {
        return Promotion::query()
            ->whereNotNull('discount_pct') // Brief-tagged only
            ->whereDate('end_date', '<', Carbon::today())
            ->whereDate('start_date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS))
            ->with('skus:id')
            ->get();
    }

    /**
     * @return list<array{features: list<float>, lift: float}>
     */
    private function buildTrainingData(Collection $promotions): array
    {
        $rows = [];
        foreach ($promotions as $promo) {
            $lift = $this->actualUplift($promo);
            if ($lift === null) {
                continue;
            }
            $rows[] = [
                'features' => $this->vectorizer->vectorize($this->briefOf($promo)),
                'lift'     => $lift,
            ];
        }
        return $rows;
    }

    private function actualUplift(Promotion $promo): ?float
    {
        $skuIds = $this->resolveAffectedSkuIds($promo);
        if (empty($skuIds)) {
            return null;
        }

        $start         = Carbon::parse($promo->start_date);
        $end           = Carbon::parse($promo->end_date);
        $baselineStart = $start->copy()->subDays(self::BASELINE_WINDOW_DAYS);
        $baselineEnd   = $start->copy()->subDay();

        $baselineAvg = (float) (SalesHistory::whereIn('sku_id', $skuIds)
            ->whereBetween('sale_date', [$baselineStart, $baselineEnd])
            ->avg('quantity_sold') ?? 0);

        $duringAvg = (float) (SalesHistory::whereIn('sku_id', $skuIds)
            ->whereBetween('sale_date', [$start, $end])
            ->avg('quantity_sold') ?? 0);

        if ($baselineAvg <= 0) {
            return null;
        }

        return (($duringAvg - $baselineAvg) / $baselineAvg) * 100;
    }

    private function resolveAffectedSkuIds(Promotion $promo): array
    {
        if ($promo->affects_all_skus) {
            return Sku::pluck('id')->toArray();
        }
        if (! empty($promo->applies_to_categories)) {
            return Sku::whereIn('category', $promo->applies_to_categories)->pluck('id')->toArray();
        }
        return $promo->skus->pluck('id')->toArray();
    }

    private function briefOf(Promotion $p): array
    {
        return [
            'promotion_type'         => $p->promotion_type,
            'discount_pct'           => $p->discount_pct,
            'discount_type'          => $p->discount_type,
            'channel_mix'            => $p->channel_mix ?? [],
            'ad_spend_band'          => $p->ad_spend_band,
            'audience'               => $p->audience,
            'lead_announcement_days' => $p->lead_announcement_days,
        ];
    }

    // ─── Python subprocess plumbing ────────────────────────────────────────

    /**
     * @return array{value: float, lower: float, upper: float, basis: string, sample_size: int, layer: string}
     */
    private function invokePython(array $payload): array
    {
        $tenantId = TenantContext::tenantId();
        $tmpDir   = "uplift/tmp/{$tenantId}";
        Storage::disk('local')->makeDirectory($tmpDir);

        $tmpRel = "{$tmpDir}/" . uniqid('predict_', true) . '.json';
        Storage::disk('local')->put($tmpRel, json_encode($payload, JSON_THROW_ON_ERROR));
        $tmpAbs = Storage::disk('local')->path($tmpRel);

        try {
            $script    = base_path('python/forecasting/uplift/main.py');
            // Cross-platform: pick the python binary from forecasting config
            // (defaults to 'python', which resolves on Windows where 'python3'
            // is not a valid command and returns exit code 9009).
            $pythonBin = config('forecasting.python_bin', 'python3');
            $result    = Process::timeout(self::SUBPROCESS_TIMEOUT_S)
                ->run([$pythonBin, $script, '--input', $tmpAbs]);

            if (! $result->successful()) {
                return $this->emptyResult(
                    'Python subprocess failed (exit ' . $result->exitCode() . ').'
                );
            }

            $stdout = trim($result->output());
            if ($stdout === '') {
                return $this->emptyResult('Python subprocess returned no output.');
            }

            $decoded = json_decode($stdout, true);
            if (! is_array($decoded) || ! isset($decoded['value'])) {
                return $this->emptyResult('Python output malformed.');
            }

            return [
                'value'       => (float) $decoded['value'],
                'lower'       => (float) ($decoded['lower'] ?? $decoded['value']),
                'upper'       => (float) ($decoded['upper'] ?? $decoded['value']),
                'basis'       => (string) ($decoded['basis'] ?? ''),
                'sample_size' => (int) ($decoded['sample_size'] ?? 0),
                'layer'       => self::LAYER_NAME,
            ];
        } catch (Throwable $e) {
            return $this->emptyResult('Subprocess invocation error: ' . $e->getMessage());
        } finally {
            Storage::disk('local')->delete($tmpRel);
        }
    }

    private function emptyResult(string $basis): array
    {
        return [
            'value'       => 0.0,
            'lower'       => 0.0,
            'upper'       => 0.0,
            'basis'       => $basis,
            'sample_size' => 0,
            'layer'       => self::LAYER_NAME,
        ];
    }
}
