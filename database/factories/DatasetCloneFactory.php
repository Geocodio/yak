<?php

namespace Database\Factories;

use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatasetClone>
 */
class DatasetCloneFactory extends Factory
{
    protected $model = DatasetClone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(1, 1000000);

        return [
            'dataset_version_id' => DatasetVersion::factory(),
            'owner_type' => Thread::class,
            'owner_id' => $id,
            'volume_name' => 'yak-clone-thread-' . $id,
            'container_name' => 'yak-thread-' . $id,
        ];
    }
}
