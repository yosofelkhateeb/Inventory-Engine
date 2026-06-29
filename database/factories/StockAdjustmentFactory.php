<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        $oldQty = fake()->numberBetween(0, 100);
        $newQty = fake()->numberBetween(0, 100);

        return [
            'sku_id'      => Sku::factory(),
            'user_id'     => User::factory(),
            'old_qty'     => $oldQty,
            'new_qty'     => $newQty,
            'delta'       => $newQty - $oldQty,
            'reason_code' => fake()->randomElement([
                'cycle_count',
                'damage_writeoff',
                'customer_return',
                'supplier_short_ship',
                'data_entry_correction',
                'internal_use',
            ]),
            'notes'       => null,
            'adjusted_at' => now(),
        ];
    }
}
