<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sku>
 */
class SkuFactory extends Factory
{
    public function definition(): array
    {
        $products = [
            'Football Boots', 'Match Ball', 'Goalkeeper Gloves', 'Shin Guards',
            'Grip Socks', 'Team Socks', 'Ball Pump', 'Training Cones',
            'Captain Armband', 'Water Bottle', 'Training Bib',
        ];

        return [
            'tenant_id' => 1,
            'supplier_id' => Supplier::factory(),
            'name' => fake()->randomElement($products).' '.fake()->word(),
            'sku_code' => fake()->unique()->numerify('SKU-####'),
            'category' => fake()->randomElement(['equipment', 'accessory', 'bundle']),
            'moq' => fake()->randomElement([6, 12, 24, 48]),
            'unit_cost' => fake()->numberBetween(500, 15000), // 5.00 – 150.00 SAR in halalas
            'reorder_qty' => fake()->numberBetween(12, 100),
            'current_stock' => fake()->numberBetween(0, 200),
            'in_transit_qty' => fake()->numberBetween(0, 50),
            'reserved_qty' => fake()->numberBetween(0, 20),
            'lead_time_days' => fake()->numberBetween(3, 21),
        ];
    }
}
