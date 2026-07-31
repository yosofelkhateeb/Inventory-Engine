<?php

namespace App\Services\Synthetic;

use Carbon\CarbonInterface;

/**
 * Shapes a raw synthetic order quantity into a realistic daily figure by
 * applying the SEA seasonal calendar and a day-of-week profile.
 *
 * Extracted so SeaDatasetSeeder (which lays down the initial window) and
 * SeaDemoFeedTopUp (which appends elapsed days to the hosted demo) shape
 * demand identically. If the two drifted, topped-up days would carry a
 * different demand shape than the history behind them, which reads as a
 * structural break to the forecasting pipeline — exactly the artefact the
 * demo is meant not to have.
 */
final class SeaDemandMultipliers
{
    private readonly SeaSeasonalCalendar $calendar;

    /** @var array<int, float> Mon=0 … Sun=6 */
    private readonly array $dowMultipliers;

    public function __construct(?SeaSeasonalCalendar $calendar = null)
    {
        $this->calendar       = $calendar ?? new SeaSeasonalCalendar;
        $this->dowMultipliers = config('synthetic_dataset.day_of_week_multipliers', [1.0, 1.0, 1.05, 1.05, 1.1, 1.25, 1.15]);
    }

    /**
     * Apply seasonal and day-of-week shaping to a raw quantity.
     *
     * Returns 0 for days that shape down to nothing; callers drop those rows
     * rather than writing explicit zeroes, matching the "no order that day"
     * convention the Shopify-shaped fixtures use.
     */
    public function apply(float $rawQuantity, CarbonInterface $date): int
    {
        $seasonal = $this->calendar->multiplierFor($date);
        $dow      = $this->dowMultipliers[$date->dayOfWeekIso - 1] ?? 1.0;

        return max(0, (int) round($rawQuantity * $seasonal * $dow));
    }

    public function calendar(): SeaSeasonalCalendar
    {
        return $this->calendar;
    }
}
