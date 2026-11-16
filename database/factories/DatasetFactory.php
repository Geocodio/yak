<?php

namespace Database\Factories;

use App\Models\Dataset;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    protected $model = Dataset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(1);

        return [
            'repository_id' => Repository::factory(),
            'name' => $name,
            'source_prefix' => 'data/' . $name,
            'version_key' => 'data/' . $name . '/CURRENT',
            'mount_path' => '/data',
            'volume_name' => 'yak-data-' . $name,
        ];
    }
}
