<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EngineRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'triggered_by'    => User::factory(),
            'run_at'          => now(),
            'status'          => 'completed',
            'decisions_count' => fake()->numberBetween(1, 20),
            'duration_ms'     => fake()->numberBetween(100, 2000),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(['triggered_by' => null]);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'failed', 'decisions_count' => 0]);
    }
}
