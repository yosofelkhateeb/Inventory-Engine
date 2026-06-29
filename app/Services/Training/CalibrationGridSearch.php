<?php

namespace App\Services\Training;

use Carbon\Carbon;

/**
 * Sweeps the 4D space of (k_lead, k_ltv, k_smape, k_trend) and returns
 * the tuple that maximises the chosen objective on the training set.
 *
 * Default grid is 5 values per dimension → 625 evaluations. Each
 * evaluation walks ~33k snapshots, ≈1s on local hardware → roughly
 * 10 minutes for a full search. Acceptable for a bi-weekly batch job.
 *
 * Objective options:
 *   'f1'   — balanced precision/recall (default)
 *   'f2'   — recall-favouring (good when missed stockouts are
 *            operationally costlier than unnecessary watches —
 *            often the inventory case)
 *   'precision_at_recall' — pick the highest-precision tuple
 *            subject to a minimum recall floor (configurable)
 *
 * Returned diagnostics include the full search trace (every evaluated
 * tuple and its metrics) so the calibration job can log parameter
 * sensitivity for monitoring.
 */
class CalibrationGridSearch
{
    /** Default grid covers the inventory-science-defensible range. */
    public const DEFAULT_GRID = [
        'k_lead'  => [0.2, 0.3, 0.5, 0.7, 1.0],   // 5
        'k_ltv'   => [0.5, 1.0, 1.65, 2.0, 2.5],  // 5
        'k_smape' => [0.0, 0.25, 0.5, 0.75, 1.0], // 5
        'k_trend' => [-2.0, -1.0, 0.0, 1.0, 2.0], // 5  (625 total)
    ];

    public function __construct(
        private readonly CalibrationOutcomeAnalyzer $analyzer,
    ) {}

    /**
     * Run the search.
     *
     * @param  string $objective  'f1' | 'f2' | 'precision_at_recall'
     * @param  float  $recallFloor  used only when objective = 'precision_at_recall'
     * @param  array<string, list<float>>|null $grid  override the default grid
     * @return array{
     *   best: array{k_lead:float, k_ltv:float, k_smape:float, k_trend:float, score:float, metrics:array},
     *   trace: list<array>,
     *   objective: string,
     *   evaluations: int,
     *   elapsed_sec: float,
     * }
     */
    public function search(
        int     $tenantId,
        string  $objective    = 'f2',
        float   $recallFloor  = 0.7,
        ?array  $grid         = null,
        ?Carbon $startDate    = null,
        ?Carbon $endDate      = null,
    ): array {
        $grid ??= self::DEFAULT_GRID;
        $started = microtime(true);
        $trace   = [];

        $best  = null;
        $bestScore = -INF;

        foreach ($grid['k_lead']  as $kLead)
        foreach ($grid['k_ltv']   as $kLtv)
        foreach ($grid['k_smape'] as $kSmape)
        foreach ($grid['k_trend'] as $kTrend) {
            $metrics = $this->analyzer->evaluate(
                $tenantId, $kLead, $kLtv, $kSmape, $kTrend, $startDate, $endDate,
            );
            $score = $this->score($objective, $metrics, $recallFloor);

            $trace[] = [
                'k_lead'  => $kLead,  'k_ltv'   => $kLtv,
                'k_smape' => $kSmape, 'k_trend' => $kTrend,
                'score'   => $score,  'metrics' => $metrics,
            ];

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'k_lead'  => $kLead,  'k_ltv'   => $kLtv,
                    'k_smape' => $kSmape, 'k_trend' => $kTrend,
                    'score'   => $score,  'metrics' => $metrics,
                ];
            }
        }

        return [
            'best'        => $best,
            'trace'       => $trace,
            'objective'   => $objective,
            'evaluations' => count($trace),
            'elapsed_sec' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * Score a candidate. Higher = better. -INF disqualifies.
     */
    private function score(string $objective, array $metrics, float $recallFloor): float
    {
        return match ($objective) {
            'f1' => $metrics['f1'],
            'f2' => $metrics['f2'],
            'precision_at_recall' => $metrics['recall'] >= $recallFloor
                ? $metrics['precision']
                : -INF,
            default => $metrics['f1'],
        };
    }
}
