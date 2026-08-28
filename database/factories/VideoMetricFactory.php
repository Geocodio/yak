<?php

namespace Database\Factories;

use App\Models\VideoMetric;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoMetric> */
class VideoMetricFactory extends Factory
{
    protected $model = VideoMetric::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'yak_task_id' => YakTask::factory(),
            'artifact_id' => null,
            'status' => VideoMetric::STATUS_RENDERED,
            'render_ms' => fake()->numberBetween(20000, 180000),
            'output_bytes' => fake()->numberBetween(1_000_000, 30_000_000),
            'duration_seconds' => fake()->randomFloat(2, 20, 120),
            'error' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => VideoMetric::STATUS_FAILED,
            'output_bytes' => null,
            'duration_seconds' => null,
            'error' => 'Remotion render failed (exit 1): boom',
        ]);
    }
}
