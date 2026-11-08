<?php

namespace Database\Factories;

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo' => fake()->slug(2),
            'status' => ThreadStatus::Open,
            'model' => 'opus',
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
            'sandbox_state' => ThreadSandboxState::Paused,
            'sandbox_state_changed_at' => now(),
            'runner_state' => RunnerState::Idle,
        ]);
    }

    public function stopped(): static
    {
        return $this->state(fn (): array => [
            'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
            'sandbox_state' => ThreadSandboxState::Stopped,
            'sandbox_state_changed_at' => now(),
            'runner_state' => RunnerState::None,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => ThreadStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
