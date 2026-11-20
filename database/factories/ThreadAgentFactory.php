<?php

namespace Database\Factories;

use App\Enums\ThreadAgentKind;
use App\Enums\ThreadAgentStatus;
use App\Models\Thread;
use App\Models\ThreadAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadAgent>
 */
class ThreadAgentFactory extends Factory
{
    protected $model = ThreadAgent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'task_id' => 'a' . fake()->unique()->regexify('[0-9a-f]{16}'),
            'tool_use_id' => 'toolu_' . fake()->unique()->regexify('[A-Za-z0-9]{20}'),
            'depth' => 1,
            'kind' => ThreadAgentKind::Agent,
            'agent_type' => 'general-purpose',
            'description' => fake()->sentence(3),
            'is_background' => true,
            'status' => ThreadAgentStatus::Running,
            'started_at' => now(),
        ];
    }

    public function finished(ThreadAgentStatus $status = ThreadAgentStatus::Completed): static
    {
        return $this->state(['status' => $status, 'finished_at' => now()]);
    }

    public function workflow(string $name = 'review'): static
    {
        return $this->state(['kind' => ThreadAgentKind::Workflow, 'workflow_name' => $name, 'agent_type' => null, 'task_id' => 'w' . fake()->unique()->regexify('[0-9a-z]{8}')]);
    }

    public function childOf(ThreadAgent $parent): static
    {
        return $this->state(['thread_id' => $parent->thread_id, 'parent_task_id' => $parent->task_id, 'depth' => $parent->depth + 1]);
    }
}
