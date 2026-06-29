<?php

namespace App\Services\Promotions\Layers;

use App\Models\Promotion;
use App\Models\SalesHistory;
use App\Models\Sku;
use App\Services\Promotions\BriefVectorizer;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Layer 2 of the Layered Hybrid Uplift Prediction Engine — activates
 * when enough tagged past campaigns exist (orchestrator gates this on
 * `uplift.min_nn_samples` from system_settings).
 *
 * Replaces the v1 UpliftSuggester's coarse SKU / category / type
 * matching with cosine distance over the full normalized Campaign
 * Brief feature space. Steps:
 *
 *   1. Vectorize the input Brief into a 23-dim normalized feature vector.
 *   2. Pull all past completed tagged promotions in the lookback window.
 *   3. Vectorize each, score by cosine distance to the input.
 *   4. Take the top-K nearest neighbors (default 10).
 *   5. Compute each neighbor's *actual* sales lift =
 *        (during_avg - baseline_avg) / baseline_avg × 100
 *      Skips neighbors with zero baseline (intermittent demand → no signal).
 *   6. Return median + 25th/75th percentile band as the prediction.
 *
 * Returns `sample_size = 0` and `value = 0` when no candidates exist or
 * none of the matches yield a usable lift. The orchestrator (Step 4)
 * checks sample_size against `uplift.min_nn_samples` to decide whether
 * to use this layer's output or fall back to Layer 1.
 *
 * Output shape matches every layer's contract:
 *   {value, lower, upper, basis, sample_size, layer}
 */
class NearestNeighborLayer
{
    public const LAYER_NAME = 'nearest_neighbor';

    private const TOP_K = 10;
    private const LOOKBACK_DAYS = 730;        // 24 months
    private const BASELINE_WINDOW_DAYS = 30;

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
        if ($candidates->isEmpty()) {
            return $this->emptyResult('No past tagged campaigns in the lookback window.');
        }

        // Score every candidate by cosine distance to the input Brief.
        $inputVec = $this->vectorizer->vectorize($brief);
        $scored = $candidates->map(fn (Promotion $p) => [
            'promotion' => $p,
            'distance'  => $this->cosineDistance($inputVec, $this->vectorizer->vectorize($this->briefOf($p))),
        ])->sortBy('distance')->take(self::TOP_K);

        // Compute actual sales lift for each top-K neighbor; some may have
        // zero baseline (intermittent demand) and drop out.
        $lifts = $scored->map(fn ($s) => $this->actualUplift($s['promotion']))
            ->filter(fn ($v) => $v !== null)
            ->values();

        if ($lifts->isEmpty()) {
            return $this->emptyResult('No comparable past campaigns produced computable lift.');
        }

        return $this->summarize($lifts);
    }

    // ─── Candidate selection ───────────────────────────────────────────────

    private function findCandidatePromotions(): Collection
    {
        return Promotion::query()
            ->whereDate('end_date', '<', Carbon::today())
            ->whereDate('start_date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS))
            ->with('skus:id')
            ->get();
    }

    // ─── Brief shape extraction ────────────────────────────────────────────

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

    /**
     * Cosine distance = 1 - cosine_similarity.
     * Zero-magnitude vectors (i.e., all-zero Briefs) get max distance 1.0.
     */
    private function cosineDistance(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $av = (float) ($a[$i] ?? 0.0);
            $bv = (float) ($b[$i] ?? 0.0);
            $dot  += $av * $bv;
            $magA += $av * $av;
            $magB += $bv * $bv;
        }
        if ($magA === 0.0 || $magB === 0.0) {
            return 1.0;
        }
        return 1.0 - ($dot / (sqrt($magA) * sqrt($magB)));
    }

    // ─── Actual sales lift (mirrors UpliftSuggester::actualUplift) ─────────

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

    // ─── Summary statistics ────────────────────────────────────────────────

    private function summarize(Collection $lifts): array
    {
        $sorted = $lifts->sort()->values();
        $count  = $sorted->count();

        $median = $this->percentile($sorted, 50);
        $p25    = $this->percentile($sorted, 25);
        $p75    = $this->percentile($sorted, 75);

        return [
            'value'       => round(max(0.0, $median), 1),
            'lower'       => round(max(0.0, $p25), 1),
            'upper'       => round(max(0.0, $p75), 1),
            'basis'       => "Based on {$count} similar past campaign(s)",
            'sample_size' => $count,
            'layer'       => self::LAYER_NAME,
        ];
    }

    /**
     * Linear-interpolation percentile (same convention numpy / pandas use
     * by default). Sorted ascending input.
     */
    private function percentile(Collection $sorted, float $p): float
    {
        $n = $sorted->count();
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sorted->first();
        }

        $rank  = ($p / 100.0) * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        $frac  = $rank - $lower;

        $lo = (float) $sorted->get($lower);
        $hi = (float) $sorted->get($upper);
        return $lo * (1.0 - $frac) + $hi * $frac;
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
