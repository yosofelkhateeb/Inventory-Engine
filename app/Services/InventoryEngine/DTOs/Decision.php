<?php

namespace App\Services\InventoryEngine\DTOs;

readonly class Decision
{
    public function __construct(
        public string $decision,        // order|watch|hold|order_budget_blocked
        public int    $recommended_qty,
        public int    $constrained_qty,
        public float  $reorder_point,
        public float  $safety_stock,
        public array  $reasoning,
    ) {}
}
