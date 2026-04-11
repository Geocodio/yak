<?php

namespace Database\Factories;

use App\Models\TaskLog;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskLog>
 */
class TaskLogFactory extends Factory
{
    protected $model = TaskLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'yak_task_id' => YakTask::factory(),
            'level' => 'info',
            'message' => fake()->sentence(),
            'created_at' => now(),
        ];
    }

    public function info(): static
    {
        return $this->state(fn (): array => [
            'level' => 'info',
            'message' => fake()->sentence(),
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (): array => [
            'level' => 'warning',
            'message' => 'Warning: ' . fake()->sentence(),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'level' => 'error',
            'message' => 'Error: ' . fake()->sentence(),
            'metadata' => ['trace' => fake()->text(200)],
        ]);
    }
}
