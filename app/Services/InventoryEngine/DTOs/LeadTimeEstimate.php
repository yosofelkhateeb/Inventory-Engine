<?php

namespace App\Services\InventoryEngine\DTOs;

readonly class LeadTimeEstimate
{
    public function __construct(
        public int    $expected_days,
        public int    $buffered_days,
        public float  $stddev,
        public ?float $p95    = null,         // null when not derivable (cold start)
        public string $source = 'static',     // 'observations_sku' | 'observations_supplier' | 'static'
    ) {}
}
