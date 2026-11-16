<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\DatasetVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatasetVersion>
 */
class DatasetVersionFactory extends Factory
{
    protected $model = DatasetVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dataset_id' => Dataset::factory(),
            'version' => 'v' . fake()->unique()->numberBetween(1, 100000),
        ];
    }
}
