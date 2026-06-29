<?php

namespace App\Services\InventoryEngine\DTOs;

readonly class InventoryPosition
{
    public function __construct(
        public int   $on_hand,
        public int   $in_transit,
        public int   $reserved,
        public int   $effective_position,
        public float $days_of_cover,
    ) {}
}
