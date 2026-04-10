<?php

namespace Database\Factories;

use App\Models\DailyCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyCost>
 */
class DailyCostFactory extends Factory
{
    protected $model = DailyCost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->date(),
            'total_usd' => fake()->randomFloat(4, 0, 50),
            'task_count' => fake()->numberBetween(0, 20),
            'updated_at' => now(),
        ];
    }
}
