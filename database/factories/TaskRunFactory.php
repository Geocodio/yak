<?php

namespace Database\Factories;

use App\Enums\TaskRunKind;
use App\Enums\TaskRunOutcome;
use App\Models\TaskRun;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskRun>
 */
class TaskRunFactory extends Factory
{
    protected $model = TaskRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $agentMs = fake()->numberBetween(60_000, 900_000);

        return [
            'yak_task_id' => YakTask::factory(),
            'kind' => TaskRunKind::Initial,
            'attempt_number' => 1,
            'job_class' => 'App\\Jobs\\RunYakJob',
            'queue' => 'yak-claude',
            'repo' => 'acme/widgets',
            'source' => 'slack',
            'mode' => 'fix',
            'outcome' => TaskRunOutcome::Success,
            'model' => 'opus',
            'started_at' => now()->subMinutes(20),
            'agent_started_at' => now()->subMinutes(19),
            'agent_finished_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'queue_wait_ms' => fake()->numberBetween(500, 60_000),
            'sandbox_create_ms' => fake()->numberBetween(2_000, 30_000),
            'git_prepare_ms' => fake()->numberBetween(1_000, 10_000),
            'agent_ms' => $agentMs,
            'post_agent_ms' => fake()->numberBetween(1_000, 20_000),
            'teardown_ms' => fake()->numberBetween(500, 5_000),
            'total_ms' => $agentMs + 60_000,
            'cost_usd' => fake()->randomFloat(4, 0.2, 4.5),
            'num_turns' => fake()->numberBetween(5, 120),
            'input_tokens' => fake()->numberBetween(1_000, 50_000),
            'output_tokens' => fake()->numberBetween(500, 20_000),
            'cache_read_tokens' => fake()->numberBetween(0, 500_000),
            'cache_creation_tokens' => fake()->numberBetween(0, 50_000),
            'tool_calls' => fake()->numberBetween(3, 80),
            'tool_errors' => fake()->numberBetween(0, 5),
            'tool_ms' => fake()->numberBetween(1_000, 300_000),
            'tool_breakdown' => [
                'Bash' => ['calls' => 10, 'errors' => 1, 'ms' => 120_000],
                'Read' => ['calls' => 20, 'errors' => 0, 'ms' => 2_000],
            ],
            'commits' => 1,
            'files_changed' => fake()->numberBetween(1, 12),
            'lines_added' => fake()->numberBetween(1, 400),
            'lines_removed' => fake()->numberBetween(0, 200),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'outcome' => TaskRunOutcome::Error,
            'error_subtype' => 'error_max_turns',
            'error_message' => 'Hit max turns limit',
            'commits' => null,
        ]);
    }

    public function retry(): static
    {
        return $this->state(fn (): array => [
            'kind' => TaskRunKind::Retry,
            'attempt_number' => 2,
            'job_class' => 'App\\Jobs\\RetryYakJob',
        ]);
    }

    public function review(): static
    {
        return $this->state(fn (): array => [
            'kind' => TaskRunKind::Review,
            'mode' => 'review',
            'source' => 'github',
            'job_class' => 'App\\Jobs\\RunYakReviewJob',
            'commits' => null,
        ]);
    }
}
