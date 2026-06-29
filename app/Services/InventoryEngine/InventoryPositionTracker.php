<?php

namespace App\Services\InventoryEngine;

use App\Models\Sku;
use App\Services\InventoryEngine\DTOs\InventoryPosition;

class InventoryPositionTracker
{
    public function getPosition(int $skuId, float $avgDailyDemand): InventoryPosition
    {
        $sku = Sku::findOrFail($skuId);
        $effective = $sku->current_stock + $sku->in_transit_qty - $sku->reserved_qty;
        $daysOfCover = $avgDailyDemand > 0
            ? round($effective / $avgDailyDemand, 2)
            : 0.0;

        return new InventoryPosition(
            on_hand: $sku->current_stock,
            in_transit: $sku->in_transit_qty,
            reserved: $sku->reserved_qty,
            effective_position: $effective,
            days_of_cover: $daysOfCover,
        );
    }
}
