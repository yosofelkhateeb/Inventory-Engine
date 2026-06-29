<?php

namespace App\Services\Synthetic;

use Carbon\Carbon;

/**
 * SEA e-commerce seasonal calendar — a per-date multiplier lookup for
 * the synthetic dataset orchestrator (Step 4) to scale ShopifyOrderFactory
 * output into a recognizably South-East-Asian Shopify shape.
 *
 * Anchored to the public calendar of SEA mega-sale days (9.9, 10.10,
 * 11.11, 12.12), Chinese New Year, Hari Raya Puasa, Hari Raya Haji,
 * Songkran, Singapore National Day, Black Friday / Cyber Monday, and
 * Christmas. Magnitudes calibrated from public Lazada/Shopee trend
 * summaries 2023–2024 — verify and re-tune once a real client's
 * Shopify history is ingested.
 *
 * Composition rule
 * ----------------
 * Multiple events can land on the same date (e.g., 11.11 + Singles Day +
 * Cyber Monday occasionally collide). Naively multiplying multipliers
 * stacks them into unrealistic 5–7× spikes. We compose by MAX across
 * positive amplifiers and then multiply the result by the MIN of any
 * negative dampeners (monsoon). Result range typically 0.90×–3.0×.
 *
 * The event date table is hardcoded for the 2023-11-12 → 2026-05-12
 * window — lunar / Islamic calendars are not computed dynamically.
 * Extending the window means adding the matching dates here.
 */
class SeaSeasonalCalendar
{
    /** @var array<string, float> Loaded from config at construction. */
    private array $weights;

    /**
     * Single-day events. Each entry: ['Y-m-d' => 'config.weight.key'].
     * Runup / runoff days for the same event use *_runup / *_runoff keys.
     */
    private const SINGLE_DAY_EVENTS = [
        // Mega sale single days
        '2023-11-11' => 'mega_1111',
        '2024-11-11' => 'mega_1111',
        '2025-11-11' => 'mega_1111',
        '2023-12-12' => 'mega_1212',
        '2024-12-12' => 'mega_1212',
        '2025-12-12' => 'mega_1212',
        '2024-09-09' => 'mega_99',
        '2025-09-09' => 'mega_99',
        '2024-10-10' => 'mega_1010',
        '2025-10-10' => 'mega_1010',
        // Black Friday + Cyber Monday (Western calendar Friday/Monday after US Thanksgiving)
        '2023-11-24' => 'black_friday',
        '2024-11-29' => 'black_friday',
        '2025-11-28' => 'black_friday',
        '2023-11-27' => 'cyber_monday',
        '2024-12-02' => 'cyber_monday',
        '2025-12-01' => 'cyber_monday',
    ];

    /**
     * Mega-sale-day runup (day before) and runoff (day after) maps.
     * Separated from SINGLE_DAY_EVENTS so a single date can match BOTH
     * a peak event and an adjacent runup of a different event without
     * ambiguity.
     */
    private const RUNUP_DAYS = [
        '2023-11-10' => 'mega_1111_runup',
        '2024-11-10' => 'mega_1111_runup',
        '2025-11-10' => 'mega_1111_runup',
        '2023-12-11' => 'mega_1212_runup',
        '2024-12-11' => 'mega_1212_runup',
        '2025-12-11' => 'mega_1212_runup',
        '2024-09-08' => 'mega_99_runup',
        '2025-09-08' => 'mega_99_runup',
        '2024-10-09' => 'mega_1010_runup',
        '2025-10-09' => 'mega_1010_runup',
    ];

    private const RUNOFF_DAYS = [
        '2023-11-12' => 'mega_1111_runoff',
        '2024-11-12' => 'mega_1111_runoff',
        '2025-11-12' => 'mega_1111_runoff',
        '2024-09-10' => 'mega_99_runoff',
        '2025-09-10' => 'mega_99_runoff',
    ];

    /**
     * Multi-day windows. Each entry: ['Y-m-d_start', 'Y-m-d_end', 'config.weight.key'].
     * Inclusive on both ends.
     */
    private const WINDOWED_EVENTS = [
        // Chinese New Year — 3-day peak window + 7-day runup
        ['2024-02-08', '2024-02-13', 'cny_peak'],
        ['2025-01-27', '2025-02-01', 'cny_peak'],
        ['2026-02-15', '2026-02-20', 'cny_peak'],
        ['2024-02-01', '2024-02-07', 'cny_runup'],
        ['2025-01-20', '2025-01-26', 'cny_runup'],
        ['2026-02-08', '2026-02-14', 'cny_runup'],
        // Hari Raya Puasa — 2-day peak + 14-day runup
        ['2024-04-08', '2024-04-12', 'hari_raya_puasa_peak'],
        ['2025-03-29', '2025-04-02', 'hari_raya_puasa_peak'],
        ['2026-03-18', '2026-03-22', 'hari_raya_puasa_peak'],
        ['2024-03-25', '2024-04-07', 'hari_raya_puasa_runup'],
        ['2025-03-15', '2025-03-28', 'hari_raya_puasa_runup'],
        ['2026-03-04', '2026-03-17', 'hari_raya_puasa_runup'],
        // Hari Raya Haji — single-day window
        ['2024-06-16', '2024-06-18', 'hari_raya_haji_peak'],
        ['2025-06-06', '2025-06-08', 'hari_raya_haji_peak'],
        ['2026-05-26', '2026-05-28', 'hari_raya_haji_peak'],
        // Christmas window (Dec 20-26)
        ['2023-12-20', '2023-12-26', 'christmas_window'],
        ['2024-12-20', '2024-12-26', 'christmas_window'],
        ['2025-12-20', '2025-12-26', 'christmas_window'],
        // Songkran (Thai New Year) — Apr 13-15 every year
        ['2024-04-13', '2024-04-15', 'songkran'],
        ['2025-04-13', '2025-04-15', 'songkran'],
        ['2026-04-13', '2026-04-15', 'songkran'],
        // Singapore National Day — Aug 9 ± 1
        ['2024-08-08', '2024-08-10', 'singapore_natl_day'],
        ['2025-08-08', '2025-08-10', 'singapore_natl_day'],
    ];

    /**
     * Dampener windows — multiplier < 1.0. Composed multiplicatively
     * with the positive-amplifier result, so a monsoon week with no
     * active mega sale lands at 0.90×.
     */
    private const DAMPENER_WINDOWS = [
        // Monsoon-season dampening, ~7-8 weeks each year (rough approximation
        // — the actual monsoon varies by SEA sub-region; this picks the
        // common late-Jun → mid-Aug overlap window, extended to Aug 12 so
        // the composition with Singapore National Day is observable).
        ['2024-06-20', '2024-08-12', 'monsoon_dampening'],
        ['2025-06-20', '2025-08-12', 'monsoon_dampening'],
    ];

    public function __construct(?array $weights = null)
    {
        $this->weights = $weights ?? config('synthetic_dataset.seasonal_calendar', []);
    }

    /**
     * Returns the composite multiplier for $date. Default 1.0 if no
     * event matches. Positive amplifiers compose by max; dampeners
     * multiply the result.
     */
    public function multiplierFor(Carbon $date): float
    {
        $key = $date->format('Y-m-d');

        $amplifiers = [];

        if (isset(self::SINGLE_DAY_EVENTS[$key])) {
            $amplifiers[] = $this->weight(self::SINGLE_DAY_EVENTS[$key]);
        }
        if (isset(self::RUNUP_DAYS[$key])) {
            $amplifiers[] = $this->weight(self::RUNUP_DAYS[$key]);
        }
        if (isset(self::RUNOFF_DAYS[$key])) {
            $amplifiers[] = $this->weight(self::RUNOFF_DAYS[$key]);
        }

        foreach (self::WINDOWED_EVENTS as [$start, $end, $weightKey]) {
            if ($key >= $start && $key <= $end) {
                $amplifiers[] = $this->weight($weightKey);
            }
        }

        $positive = empty($amplifiers) ? 1.0 : max($amplifiers);

        $dampener = 1.0;
        foreach (self::DAMPENER_WINDOWS as [$start, $end, $weightKey]) {
            if ($key >= $start && $key <= $end) {
                $dampener = min($dampener, $this->weight($weightKey, default: 1.0));
            }
        }

        return $positive * $dampener;
    }

    private function weight(string $key, float $default = 1.0): float
    {
        return (float) ($this->weights[$key] ?? $default);
    }
}
