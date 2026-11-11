<?php

namespace Database\Factories;

use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\TaskRun;
use App\Models\ThreadTurnMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadTurnMessage>
 */
class ThreadTurnMessageFactory extends Factory
{
    protected $model = ThreadTurnMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_run_id' => TaskRun::factory(),
            'uuid' => fake()->uuid(),
            'kind' => TurnMessageKind::Prompt,
            'state' => TurnMessageState::Sent,
        ];
    }

    public function steer(): static
    {
        return $this->state(['kind' => TurnMessageKind::Steer]);
    }
}
