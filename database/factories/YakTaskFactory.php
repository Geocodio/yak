<?php

namespace Database\Factories;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YakTask>
 */
class YakTaskFactory extends Factory
{
    protected $model = YakTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => fake()->randomElement(['sentry', 'slack', 'linear', 'manual']),
            'repo' => fake()->slug(2),
            'external_id' => strtoupper(fake()->lexify('???')).'-'.fake()->unique()->numberBetween(1000, 9999),
            'external_url' => fake()->url(),
            'description' => fake()->sentence(),
            'status' => TaskStatus::Pending,
            'mode' => TaskMode::Fix,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Pending,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'model_used' => 'opus',
            'branch_name' => 'yak/'.fake()->slug(2),
        ]);
    }

    public function awaitingClarification(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::AwaitingClarification,
            'source' => 'slack',
            'started_at' => now(),
            'slack_channel' => 'C'.fake()->numerify('##########'),
            'slack_thread_ts' => fake()->numerify('##########.######'),
            'clarification_options' => ['Option A', 'Option B', 'Option C'],
            'clarification_expires_at' => now()->addDays(3),
        ]);
    }

    public function awaitingCi(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::AwaitingCi,
            'started_at' => now(),
            'model_used' => 'opus',
            'branch_name' => 'yak/'.fake()->slug(2),
        ]);
    }

    public function retrying(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Retrying,
            'started_at' => now(),
            'attempts' => 1,
            'model_used' => 'opus',
            'branch_name' => 'yak/'.fake()->slug(2),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Success,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'model_used' => 'opus',
            'pr_url' => fake()->url(),
            'result_summary' => fake()->paragraph(),
            'cost_usd' => fake()->randomFloat(4, 0.5, 5.0),
            'duration_ms' => fake()->numberBetween(60000, 600000),
            'num_turns' => fake()->numberBetween(5, 40),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Failed,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'model_used' => 'opus',
            'error_log' => fake()->paragraph(),
            'cost_usd' => fake()->randomFloat(4, 0.5, 5.0),
            'duration_ms' => fake()->numberBetween(60000, 600000),
            'num_turns' => fake()->numberBetween(5, 40),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Expired,
            'source' => 'slack',
            'started_at' => now()->subDays(4),
            'completed_at' => now(),
            'clarification_options' => ['Option A', 'Option B'],
            'clarification_expires_at' => now()->subDay(),
        ]);
    }
}
