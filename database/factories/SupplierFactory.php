<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'name' => fake()->company(),
            'avg_lead_time_days' => fake()->numberBetween(5, 14),
            'lead_time_stddev' => fake()->randomFloat(2, 0.5, 3.0),
        ];
    }
}
