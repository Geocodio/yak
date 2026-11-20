<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadAgentKind;
use App\Enums\ThreadAgentStatus;
use App\Enums\TurnState;
use App\Http\Resources\ThreadAgentsData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps one row per agent or workflow Claude starts in a thread, from the runner's task lines
 * (`task_started`, `task_progress`, `task_notification`), and sends the person's stop commands.
 * Runs inside the driver's transaction with the thread row locked; the thread events go out
 * after the commit as closures in the caller's event list.
 */
class ThreadAgents
{
    private const TASK_KINDS = ['local_agent' => ThreadAgentKind::Agent, 'local_workflow' => ThreadAgentKind::Workflow];

    public const RUNNER_LOST = 'The runner stopped before this agent reported back.';

    public function __construct(
        private readonly ThreadCommands $commands,
        private readonly ThreadEventPublisher $publisher,
    ) {}

    /**
     * Applies one redacted runner line. `$run` is the turn that is active now, if any.
     *
     * @param  array<string, mixed>  $line
     * @param  list<object>  $events
     */
    public function apply(Thread $thread, ?TaskRun $run, array $line, array &$events): void
    {
        $type = $line['type'] ?? null;

        if ($type === 'assistant') {
            $this->markForwarded($thread, $line);

            return;
        }

        if ($type === 'runner' && ($line['event'] ?? null) === 'agent_stop_failed') {
            $this->recordStopFailure($thread, $line, $events);

            return;
        }

        if ($type !== 'system') {
            return;
        }

        match ($line['subtype'] ?? null) {
            'task_started' => $this->started($thread, $run, $line, $events),
            'task_progress' => $this->progress($thread, $line, $events),
            'task_notification' => $this->finished($thread, $run, $line, $events),
            default => null,
        };
    }

    /**
     * Fails every running agent of the thread: the runner that held them is gone and will never report.
     *
     * @param  list<object>  $events
     */
    public function failRunning(Thread $thread, array &$events, string $reason = self::RUNNER_LOST): int
    {
        $agents = ThreadAgent::query()->where('thread_id', $thread->id)->running()->get();

        foreach ($agents as $agent) {
            $this->fail($thread->id, $agent, $reason, $events);
        }

        return $agents->count();
    }

    /**
     * Fails the running agents of a thread outside the driver's transaction.
     */
    public function failAll(Thread $thread): void
    {
        $events = [];
        $this->failRunning($thread, $events);
        $this->flush($events);
    }

    /**
     * Agents still marked running on a thread whose runner is gone are dead; one pass over every such thread.
     */
    public function failOrphans(): void
    {
        ThreadAgent::query()->running()
            ->whereIn('thread_id', Thread::query()->withoutGlobalScopes()->where('runner_state', RunnerState::None->value)->select('id'))
            ->distinct()->pluck('thread_id')
            ->each(function (int $threadId): void {
                $events = [];

                DB::transaction(function () use ($threadId, &$events): void {
                    $thread = Thread::query()->withoutGlobalScopes()->lockForUpdate()->find($threadId);

                    if ($thread !== null && $thread->runner_state === RunnerState::None) {
                        $this->failRunning($thread, $events);
                    }
                });

                $this->flush($events);
            });
    }

    /**
     * Asks the runner to stop an agent and every running agent it started: stopping a parent does not
     * stop its children (checked live against SDK 0.3.319). With no runner there is nothing to ask,
     * so they are marked failed. Returns the agents the stop was requested for.
     *
     * @return list<ThreadAgent>
     */
    public function stop(Thread $thread, ThreadAgent $agent): array
    {
        $running = ThreadAgent::query()->where('thread_id', $thread->id)->running()->get();
        $targets = [$agent->task_id => $agent];
        $queue = [$agent->task_id];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach ($running->where('parent_task_id', $parentId) as $child) {
                if (! isset($targets[$child->task_id])) {
                    $targets[$child->task_id] = $child;
                    $queue[] = $child->task_id;
                }
            }
        }

        return $this->requestStop($thread, array_values($targets));
    }

    /**
     * @return list<ThreadAgent>
     */
    public function stopAll(Thread $thread): array
    {
        return $this->requestStop($thread, array_values(ThreadAgent::query()->where('thread_id', $thread->id)->running()->get()->all()));
    }

    /**
     * The text Claude is told to pass to an agent.
     */
    public static function steeringText(ThreadAgent $agent, string $text): string
    {
        return 'Pass this to the agent ' . $agent->label() . ' (' . $agent->task_id . '): ' . $text;
    }

    /**
     * Whether a turn is Claude's reply to a finished agent while other agents still run: nothing is
     * announced for it, the last one speaks for all.
     */
    public static function isQuietTurn(TaskRun $run): bool
    {
        return $run->kind === TaskRunKind::AgentFollowUp && $run->turn_state === TurnState::Completed && $run->thread_id !== null && ThreadAgent::hasRunning($run->thread_id);
    }

    /**
     * The agents that finished and have no turn of Claude's after them yet, oldest first.
     *
     * @return Collection<int, ThreadAgent>
     */
    public function unclaimed(Thread $thread): Collection
    {
        return ThreadAgent::query()->where('thread_id', $thread->id)->where('depth', 1)
            ->whereNull('follow_up_run_id')->whereNotNull('finished_at')->orderBy('finished_at')->orderBy('id')->get();
    }

    /**
     * @param  list<object>  $events
     * @param  array<string, mixed>  $line
     */
    private function started(Thread $thread, ?TaskRun $run, array $line, array &$events): void
    {
        $kind = self::TASK_KINDS[(string) ($line['task_type'] ?? '')] ?? null;
        $taskId = (string) ($line['task_id'] ?? '');

        if ($kind === null || $taskId === '') {
            return;
        }

        // A finished agent that Claude resumes starts again under the same id.
        $agent = ThreadAgent::query()->firstOrNew(['thread_id' => $thread->id, 'task_id' => $taskId]);
        $agent->fill([
            'task_run_id' => $run->id ?? $agent->task_run_id,
            'follow_up_run_id' => null,
            'parent_task_id' => $line['parent_task_id'] ?? null,
            'tool_use_id' => $line['tool_use_id'] ?? $agent->tool_use_id,
            'depth' => max(1, (int) ($line['spawn_depth'] ?? 1)),
            'kind' => $kind,
            'agent_type' => $line['subagent_type'] ?? $agent->agent_type,
            'description' => $line['description'] ?? $agent->description,
            'workflow_name' => $line['workflow_name'] ?? null,
            'model' => $line['model'] ?? $agent->model,
            'is_background' => (bool) ($line['is_backgrounded'] ?? $kind === ThreadAgentKind::Workflow),
            'status' => ThreadAgentStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'stop_requested_at' => null,
            'stop_error' => null,
        ])->save();

        $this->publish($thread->id, 'agent.started', $agent, $events);
    }

    /**
     * @param  list<object>  $events
     * @param  array<string, mixed>  $line
     */
    private function progress(Thread $thread, array $line, array &$events): void
    {
        $agent = $this->find($thread, $line);

        if ($agent === null || ! $agent->isRunning()) {
            return;
        }

        $usage = (array) ($line['usage'] ?? []);

        $agent->fill(array_filter([
            'last_tool' => $line['last_tool_name'] ?? null,
            'summary' => $line['summary'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'tool_uses' => $usage['tool_uses'] ?? null,
            'duration_ms' => $usage['duration_ms'] ?? null,
            'workflow_progress' => $line['workflow_progress'] ?? null,
        ], fn (mixed $value): bool => $value !== null))->save();

        // Progress arrives with every tool call, so it is shown live and not stored; the row has the state.
        $this->publish($thread->id, 'agent.progress', $agent, $events, isStored: false);
    }

    /**
     * @param  list<object>  $events
     * @param  array<string, mixed>  $line
     */
    private function finished(Thread $thread, ?TaskRun $run, array $line, array &$events): void
    {
        $agent = $this->find($thread, $line);

        if ($agent === null) {
            return;
        }

        $usage = (array) ($line['usage'] ?? []);

        $agent->fill(array_filter([
            'status' => ThreadAgentStatus::fromNotification(is_string($line['status'] ?? null) ? $line['status'] : null),
            'summary' => is_string($line['summary'] ?? null) && $line['summary'] !== '' ? $line['summary'] : null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'tool_uses' => $usage['tool_uses'] ?? null,
            'duration_ms' => $usage['duration_ms'] ?? null,
            'finished_at' => now(),
            // A reply that is already running takes the finish in; otherwise the next turn Claude starts by itself does.
            'follow_up_run_id' => $run?->id,
        ], fn (mixed $value): bool => $value !== null))->save();

        $this->publish($thread->id, 'agent.finished', $agent, $events);
    }

    /**
     * Claude passing a person's message on is a SendMessage call addressed to the agent.
     *
     * @param  array<string, mixed>  $line
     */
    private function markForwarded(Thread $thread, array $line): void
    {
        if (($line['parent_tool_use_id'] ?? null) !== null || ($line['agent_id'] ?? null) !== null) {
            return;
        }

        foreach ((array) ($line['message']['content'] ?? []) as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'tool_use' || ($block['name'] ?? null) !== 'SendMessage' || ! is_string($block['input']['to'] ?? null)) {
                continue;
            }

            ThreadAgent::query()->where('thread_id', $thread->id)->where('task_id', $block['input']['to'])->first()
                ?->messages()->whereNull('forwarded_at')->update(['forwarded_at' => now()]);
        }
    }

    /**
     * @param  list<object>  $events
     * @param  array<string, mixed>  $line
     */
    private function recordStopFailure(Thread $thread, array $line, array &$events): void
    {
        $agent = ThreadAgent::query()->where('thread_id', $thread->id)->where('task_id', (string) ($line['taskId'] ?? ''))->first();

        if ($agent === null || ! $agent->isRunning()) {
            return;
        }

        $agent->forceFill(['stop_error' => mb_substr((string) ($line['error'] ?? 'The stop did not go through.'), 0, 500)])->save();
        $this->publish($thread->id, 'agent.progress', $agent, $events, isStored: false);
    }

    /**
     * @param  list<ThreadAgent>  $agents
     * @return list<ThreadAgent>
     */
    private function requestStop(Thread $thread, array $agents): array
    {
        $requested = [];
        $events = [];

        foreach ($agents as $agent) {
            if (! $agent->isRunning()) {
                continue;
            }

            if ($thread->runner_state === RunnerState::None) {
                $this->fail($thread->id, $agent, self::RUNNER_LOST, $events);
            } else {
                $this->commands->stopAgent($thread, $agent->task_id);
                $agent->forceFill(['stop_requested_at' => now(), 'stop_error' => null])->save();
                $this->publish($thread->id, 'agent.progress', $agent, $events, isStored: false);
            }

            $requested[] = $agent;
        }

        $this->flush($events);

        return $requested;
    }

    /**
     * @param  list<object>  $events
     */
    private function fail(int $threadId, ThreadAgent $agent, string $reason, array &$events): void
    {
        $agent->forceFill(['status' => ThreadAgentStatus::Failed, 'finished_at' => now(), 'summary' => $agent->summary ?? $reason])->save();
        $this->publish($threadId, 'agent.finished', $agent, $events);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function find(Thread $thread, array $line): ?ThreadAgent
    {
        $taskId = (string) ($line['task_id'] ?? '');

        return $taskId === '' ? null : ThreadAgent::query()->where('thread_id', $thread->id)->where('task_id', $taskId)->first();
    }

    /**
     * @param  list<object>  $events
     */
    private function publish(int $threadId, string $type, ThreadAgent $agent, array &$events, bool $isStored = true): void
    {
        $data = ThreadAgentsData::row($agent);
        $events[] = fn () => $this->publisher->append($threadId, $type, $data, $isStored);
    }

    /**
     * @param  list<object>  $events
     */
    private function flush(array $events): void
    {
        foreach ($events as $event) {
            try {
                $event instanceof Closure ? $event() : event($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
