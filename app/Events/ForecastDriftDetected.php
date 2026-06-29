<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ForecastDriftDetected
{
    use Dispatchable;

    public function __construct(
        public readonly int $skuId,
        public readonly int $tenantId,
        public readonly string $reason,
    ) {}
}
