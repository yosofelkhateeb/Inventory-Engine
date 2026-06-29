<?php

namespace App\Services\InventoryEngine\DTOs;

readonly class ForecastResult
{
    public function __construct(
        public float   $daily_demand,
        public float   $demand_stddev,
        public float   $horizon_demand,
        public int     $horizon_days,
        public string  $method, // model_name from registry, or 'weighted_moving_average' | 'no_data'
        public ?float  $smape           = null,    // null when not evaluated (insufficient history)
        public ?string $trend_direction = null,    // 'upward' | 'flat' | 'declining' | null
    ) {}
}
