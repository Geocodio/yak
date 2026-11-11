<?php

namespace Database\Factories;

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadRestoreStep>
 */
class ThreadRestoreStepFactory extends Factory
{
    protected $model = ThreadRestoreStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'position' => 1,
            'step' => RestoreStepName::CreateSandbox,
            'status' => RestoreStepStatus::Pending,
        ];
    }

    public function failed(string $error = 'Step failed'): static
    {
        return $this->state([
            'status' => RestoreStepStatus::Failed,
            'error' => $error,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}
