<?php

use App\Exceptions\InvalidForecastOutputException;
use App\Services\Forecasting\ForecastPresenter;

it('humanReadableForecast returns a correctly formatted sentence and scaled values', function () {
    $presenter = new ForecastPresenter(tenantId: 1);

    $output = $presenter->humanReadableForecast([
        'demand_rate'                 => 2.0,
        'forecast_horizon_days'       => 7,
        'interval_lower'              => 1.5,
        'interval_upper'              => 2.5,
        'interval_confidence'         => 0.80,
        'interval_empirical_coverage' => 0.85,
    ]);

    expect($output['point'])->toBe(14)
        ->and($output['lower'])->toBe(11)           // round(1.5 * 7)
        ->and($output['upper'])->toBe(18)           // round(2.5 * 7)
        ->and($output['sentence'])->toContain('~14 units per week')
        ->and($output['sentence'])->toContain('between 11 and 18');
});

it('confidenceLabel returns high when coverage exceeds target and intervals are narrow', function () {
    $presenter = new ForecastPresenter(tenantId: 1);

    // coverage = 0.90 > 0.80 target; width ratio = 0.30 < 0.50 threshold
    $label = $presenter->confidenceLabel(0.90, 0.30);

    expect($label)->toBe('high');
});

it('confidenceLabel returns low when empirical coverage is below the target', function () {
    $presenter = new ForecastPresenter(tenantId: 1);

    // coverage = 0.70 < 0.80 target → low regardless of interval width
    $label = $presenter->confidenceLabel(0.70, 0.20);

    expect($label)->toBe('low');
});

it('humanReadableForecast throws InvalidForecastOutputException on missing fields', function () {
    $presenter = new ForecastPresenter(tenantId: 1);

    expect(fn () => $presenter->humanReadableForecast(['demand_rate' => 1.0]))
        ->toThrow(InvalidForecastOutputException::class);
});
