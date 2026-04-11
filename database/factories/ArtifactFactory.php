<?php

namespace Database\Factories;

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    protected $model = Artifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug(2) . '.png';

        return [
            'yak_task_id' => YakTask::factory(),
            'type' => 'screenshot',
            'filename' => $filename,
            'disk_path' => '/home/yak/artifacts/' . $filename,
            'size_bytes' => fake()->numberBetween(10000, 5000000),
            'created_at' => now(),
        ];
    }

    public function screenshot(): static
    {
        return $this->state(function (): array {
            $filename = fake()->slug(2) . '.png';

            return [
                'type' => 'screenshot',
                'filename' => $filename,
                'disk_path' => '/home/yak/artifacts/' . $filename,
                'size_bytes' => fake()->numberBetween(50000, 2000000),
            ];
        });
    }

    public function video(): static
    {
        return $this->state(function (): array {
            $filename = fake()->slug(2) . '.mp4';

            return [
                'type' => 'video',
                'filename' => $filename,
                'disk_path' => '/home/yak/artifacts/' . $filename,
                'size_bytes' => fake()->numberBetween(1000000, 50000000),
            ];
        });
    }

    public function research(): static
    {
        return $this->state(fn (): array => [
            'type' => 'research',
            'filename' => 'research.html',
            'disk_path' => '/home/yak/artifacts/research.html',
            'size_bytes' => fake()->numberBetween(5000, 100000),
        ]);
    }
}
