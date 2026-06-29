<?php

namespace App\Services\InventoryEngine;

use App\Models\Sku;
use App\Services\InventoryEngine\DTOs\ConstrainedQuantity;

class ConstraintEngine
{
    public function applyConstraints(int $skuId, int $rawQty, int $budgetRemainingHalalas): ConstrainedQuantity
    {
        $sku   = Sku::findOrFail($skuId);
        $notes = [];

        // Round up to MOQ
        $qty = max($rawQty, $sku->moq);
        if ($qty % $sku->moq !== 0) {
            $qty = (int) (ceil($qty / $sku->moq) * $sku->moq);
            $notes[] = "Rounded up to MOQ multiple: {$qty}";
        }

        // Budget check
        $totalCost = $qty * $sku->unit_cost;
        if ($totalCost > $budgetRemainingHalalas) {
            $moqCost = $sku->moq * $sku->unit_cost;
            if ($moqCost > $budgetRemainingHalalas) {
                $notes[] = 'Budget insufficient for minimum order';
                return new ConstrainedQuantity($rawQty, 0, true, $notes);
            }
            $qty = (int) (floor($budgetRemainingHalalas / $sku->unit_cost / $sku->moq) * $sku->moq);
            $notes[] = "Reduced to {$qty} due to budget constraint";
        }

        return new ConstrainedQuantity($rawQty, $qty, false, $notes);
    }
}
