<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadWalkthrough;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadWalkthrough>
 */
class ThreadWalkthroughFactory extends Factory
{
    protected $model = ThreadWalkthrough::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'yak_task_id' => YakTask::factory(),
            'number' => 1,
            'anchor_sequence' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
