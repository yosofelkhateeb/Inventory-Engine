<?php

namespace App\Services\InventoryEngine\DTOs;

readonly class ConstrainedQuantity
{
    public function __construct(
        public int   $raw_qty,
        public int   $final_qty,
        public bool  $budget_blocked,
        public array $constraint_notes,
    ) {}
}
