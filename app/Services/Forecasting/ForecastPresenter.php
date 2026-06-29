<?php

namespace App\Services\Forecasting;

use App\Exceptions\InvalidForecastOutputException;
use App\Models\SystemSetting;

class ForecastPresenter
{
    private const REQUIRED_KEYS = [
        'demand_rate',
        'interval_lower',
        'interval_upper',
        'interval_confidence',
        'interval_empirical_coverage',
    ];

    public function __construct(private readonly int $tenantId = 1) {}

    /**
     * Transform a raw forecast registry array into human-readable UI fields.
     *
     * @throws InvalidForecastOutputException
     */
    public function humanReadableForecast(array $forecastOutput): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $forecastOutput)) {
                throw new InvalidForecastOutputException("Missing required forecast field: '{$key}'.");
            }
        }

        $demandRate  = (float) $forecastOutput['demand_rate'];
        $horizonDays = (int) ($forecastOutput['forecast_horizon_days'] ?? 7);
        $lower       = (float) $forecastOutput['interval_lower'];
        $upper       = (float) $forecastOutput['interval_upper'];
        $coverage    = (float) $forecastOutput['interval_empirical_coverage'];

        $point      = (int) round($demandRate * $horizonDays);
        $lowerPoint = (int) round($lower * $horizonDays);
        $upperPoint = (int) round($upper * $horizonDays);

        $widthRatio       = $point > 0 ? ($upper - $lower) * $horizonDays / $point : 0.0;
        $confidenceLabel  = $this->confidenceLabel($coverage, $widthRatio);

        $sentence = "Expected ~{$point} units per week (likely between {$lowerPoint} and {$upperPoint})";

        return [
            'sentence'        => $sentence,
            'point'           => $point,
            'lower'           => $lowerPoint,
            'upper'           => $upperPoint,
            'confidence_label' => $confidenceLabel,
        ];
    }

    /**
     * Derive a confidence label from empirical coverage and interval width ratio.
     *
     * @param float $coverage      Empirical coverage fraction (0–1)
     * @param float $intervalWidth Interval width as a fraction of the point forecast
     */
    public function confidenceLabel(float $coverage, float $intervalWidth): string
    {
        $target    = (float) SystemSetting::get($this->tenantId, 'forecast.confidence_coverage_target', 0.80);
        $highMax   = (float) SystemSetting::get($this->tenantId, 'forecast.confidence_high_max_width_pct', 50) / 100;
        $mediumMax = (float) SystemSetting::get($this->tenantId, 'forecast.confidence_medium_max_width_pct', 100) / 100;

        if ($coverage < $target) {
            return 'low';
        }

        return match (true) {
            $intervalWidth < $highMax   => 'high',
            $intervalWidth <= $mediumMax => 'medium',
            default                     => 'low',
        };
    }
}
