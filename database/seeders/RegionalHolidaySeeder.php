<?php

namespace Database\Seeders;

use App\Models\RegionalHoliday;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds public holidays for the client's country/region.
 *
 * The country is read from CLIENT_COUNTRY_CODE in .env (default: MY).
 * Change that value to switch holiday sets without touching this file.
 *
 * Long-term: this will move to system_settings (Phase 4) once that
 * key-value store is available, so the owner can change it from the UI.
 */
class RegionalHolidaySeeder extends Seeder
{
    public function run(): void
    {
        $countryCode = env('CLIENT_COUNTRY_CODE', 'MY');

        // Seed all years covered by the SEA synthetic dataset lookback window
        // (2023-11-12 through 2026-05-12) plus the current/upcoming year so
        // SARIMAX has the holiday regressor for both historical training and
        // forward forecasts. Once a real client is connected this can shrink
        // back to just the current year.
        $years = array_unique([2023, 2024, 2025, 2026, (int) date('Y')]);
        sort($years);

        foreach ($years as $year) {
            $holidays = match ($countryCode) {
                'MY'    => $this->malaysiaHolidays($year),
                'SA'    => $this->saudiHolidays($year),
                default => $this->malaysiaHolidays($year),
            };

            foreach ($holidays as $holiday) {
                RegionalHoliday::updateOrCreate(
                    [
                        'country_code' => $countryCode,
                        'holiday_date' => $holiday['holiday_date'],
                    ],
                    [
                        'holiday_name'       => $holiday['holiday_name'],
                        'year'               => $year,
                        'default_uplift_pct' => $holiday['default_uplift_pct'],
                    ]
                );
            }
        }
    }

    // ─── Malaysia ─────────────────────────────────────────────────────────────

    private function malaysiaHolidays(int $year): array
    {
        return [
            [
                'holiday_name'       => "New Year's Day",
                'holiday_date'       => "{$year}-01-01",
                'default_uplift_pct' => 10.0,
            ],
            [
                'holiday_name'       => 'Chinese New Year',
                // Approximate — exact date shifts yearly with the lunar calendar
                'holiday_date'       => $this->approximateChineseNewYear($year),
                'default_uplift_pct' => 25.0,
            ],
            [
                'holiday_name'       => 'Hari Raya Aidilfitri',
                // Approximate — client overrides via promotions table when exact dates confirmed
                'holiday_date'       => $this->approximateEidAlFitr($year),
                'default_uplift_pct' => 35.0,
            ],
            [
                'holiday_name'       => 'Labour Day',
                'holiday_date'       => "{$year}-05-01",
                'default_uplift_pct' => 10.0,
            ],
            [
                'holiday_name'       => 'Hari Raya Aidiladha',
                'holiday_date'       => $this->approximateEidAlAdha($year),
                'default_uplift_pct' => 20.0,
            ],
            [
                'holiday_name'       => 'Hari Merdeka (National Day)',
                'holiday_date'       => "{$year}-08-31",
                'default_uplift_pct' => 15.0,
            ],
            [
                'holiday_name'       => 'Malaysia Day',
                'holiday_date'       => "{$year}-09-16",
                'default_uplift_pct' => 15.0,
            ],
            [
                'holiday_name'       => 'Deepavali',
                // Approximate — shifts with the Hindu calendar
                'holiday_date'       => $this->approximateDeepavali($year),
                'default_uplift_pct' => 15.0,
            ],
            [
                'holiday_name'       => 'Christmas Day',
                'holiday_date'       => "{$year}-12-25",
                'default_uplift_pct' => 20.0,
            ],
        ];
    }

    // ─── Saudi Arabia (kept as a second locale option) ────────────────────────

    private function saudiHolidays(int $year): array
    {
        return [
            [
                'holiday_name'       => "New Year's Day",
                'holiday_date'       => "{$year}-01-01",
                'default_uplift_pct' => 10.0,
            ],
            [
                'holiday_name'       => 'Founding Day',
                'holiday_date'       => "{$year}-02-22",
                'default_uplift_pct' => 15.0,
            ],
            [
                'holiday_name'       => 'Eid Al-Fitr',
                'holiday_date'       => $this->approximateEidAlFitr($year),
                'default_uplift_pct' => 35.0,
            ],
            [
                'holiday_name'       => 'Eid Al-Adha',
                'holiday_date'       => $this->approximateEidAlAdha($year),
                'default_uplift_pct' => 35.0,
            ],
            [
                'holiday_name'       => 'Saudi National Day',
                'holiday_date'       => "{$year}-09-23",
                'default_uplift_pct' => 20.0,
            ],
        ];
    }

    // ─── Date helpers ─────────────────────────────────────────────────────────

    /**
     * Approximate Chinese New Year (first day of the lunar new year).
     * Base reference: 2024 CNY = February 10.
     * The date cycles roughly every 19 years (Metonic cycle) but shifts
     * between late January and late February annually.
     */
    private function approximateChineseNewYear(int $year): string
    {
        // Known anchors; interpolate for other years with a rough formula.
        $anchors = [
            2024 => '2024-02-10',
            2025 => '2025-01-29',
            2026 => '2026-02-17',
            2027 => '2027-02-06',
            2028 => '2028-01-26',
        ];

        if (isset($anchors[$year])) {
            return $anchors[$year];
        }

        // Fallback: approximate as early February
        return "{$year}-02-05";
    }

    /**
     * Approximate Deepavali (Festival of Lights).
     * Base reference: 2024 Deepavali ≈ October 31.
     * Shifts ~11 days earlier each year in the Hindu calendar.
     */
    private function approximateDeepavali(int $year): string
    {
        $anchors = [
            2024 => '2024-10-31',
            2025 => '2025-10-20',
            2026 => '2026-11-08',
            2027 => '2027-10-29',
        ];

        if (isset($anchors[$year])) {
            return $anchors[$year];
        }

        return "{$year}-10-28";
    }

    /**
     * Approximate Eid Al-Fitr date (Hari Raya Aidilfitri).
     * The Hijri calendar shifts ~11 days earlier each Gregorian year.
     * Dates are hardcoded for known years; client should override via
     * the promotions table once the official announcement is made.
     */
    private function approximateEidAlFitr(int $year): string
    {
        $anchors = [
            2024 => '2024-04-10',
            2025 => '2025-03-30',
            2026 => '2026-03-20',
            2027 => '2027-03-09',
            2028 => '2028-02-27',
        ];

        if (isset($anchors[$year])) {
            return $anchors[$year];
        }

        // Fallback for years outside the hardcoded range
        $base = Carbon::create(2024, 4, 10);
        $shifted = $base->addDays(($year - 2024) * (-11));
        return Carbon::create($year, $shifted->month, $shifted->day)->format('Y-m-d');
    }

    /**
     * Approximate Eid Al-Adha date (Hari Raya Aidiladha).
     * Base reference: 2024 Eid Al-Adha ≈ June 17.
     */
    private function approximateEidAlAdha(int $year): string
    {
        $anchors = [
            2024 => '2024-06-17',
            2025 => '2025-06-06',
            2026 => '2026-05-26',
            2027 => '2027-05-16',
            2028 => '2028-05-05',
        ];

        if (isset($anchors[$year])) {
            return $anchors[$year];
        }

        $base = Carbon::create(2024, 6, 17);
        $shifted = $base->addDays(($year - 2024) * (-11));
        return Carbon::create($year, $shifted->month, $shifted->day)->format('Y-m-d');
    }
}
