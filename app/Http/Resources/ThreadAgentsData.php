<?php

namespace App\Http\Resources;

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadAgentMessage;
use App\Services\Stacks\StackListing;
use App\Services\Threads\ThreadTurns;

/**
 * The Agents panel of a thread in one tree: Claude itself (the root), the agents and workflows it
 * started inside this checkout, and the sub-threads (separate threads with their own branch). Agents
 * come flat with `parentTaskId` and `depth`; the page builds the nesting. The array shapes here are
 * the source of truth for `resources/js/types/thread-agents.ts`.
 *
 * @phpstan-type AgentRow array{id: string, kind: string, parentTaskId: ?string, toolUseId: ?string, depth: int, agentType: ?string, description: ?string, prompt: ?string, workflowName: ?string, model: ?string, isBackground: bool, status: string, lastTool: ?string, lastToolTarget: ?string, summary: ?string, totalTokens: int, toolUses: int, durationMs: int, startedAt: ?string, finishedAt: ?string, stopRequestedAt: ?string, stopError: ?string, workflowProgress: ?list<array<string, mixed>>, startedByTurnId: ?int, followUpTurnId: ?int}
 * @phpstan-type AgentRowWithMessages array{id: string, kind: string, parentTaskId: ?string, toolUseId: ?string, depth: int, agentType: ?string, description: ?string, prompt: ?string, workflowName: ?string, model: ?string, isBackground: bool, status: string, lastTool: ?string, lastToolTarget: ?string, summary: ?string, totalTokens: int, toolUses: int, durationMs: int, startedAt: ?string, finishedAt: ?string, stopRequestedAt: ?string, stopError: ?string, workflowProgress: ?list<array<string, mixed>>, startedByTurnId: ?int, followUpTurnId: ?int, messages: list<AgentMessageRow>}
 * @phpstan-type AgentMessageRow array{id: int, text: string, authorName: ?string, sentAt: string, forwardedAt: ?string}
 * @phpstan-type RunningAgents array{runningCount: int, running: list<array{id: string, name: string, state: 'running'|'stopping', startedAt: ?string}>}
 * @phpstan-type SubThreadRow array{id: string, name: string, title: string, kind: string, state: 'starting'|'queued'|'working'|'waiting'|'done'|'failed'|'closed', step: ?string, branch: ?string, pullRequestUrl: ?string}
 */
final class ThreadAgentsData
{
    /**
     * `main.state` is `working` while Claude replies, `waiting` while only agents run and `idle` otherwise.
     *
     * @return array{
     *     main: array{state: 'working'|'waiting'|'idle', stepsThisTurn: ?int, runningAgents: int},
     *     agents: list<AgentRowWithMessages>,
     *     subThreads: list<SubThreadRow>,
     *     runningCount: int,
     * }
     */
    public static function for(Thread $thread): array
    {
        $agents = ThreadAgent::query()->where('thread_id', $thread->id)->with('messages.user')->orderBy('id')->get();
        $running = $agents->filter(fn (ThreadAgent $agent): bool => $agent->isRunning())->count();
        $turn = ThreadTurns::activeTurn($thread);

        return [
            'main' => [
                'state' => $turn !== null ? 'working' : ($running > 0 ? 'waiting' : 'idle'),
                'stepsThisTurn' => $turn === null ? null : (int) $turn->getAttribute('assistant_turns'),
                'runningAgents' => $running,
            ],
            'agents' => array_values($agents->map(fn (ThreadAgent $agent): array => [
                ...self::row($agent),
                'messages' => array_values($agent->messages->map(fn (ThreadAgentMessage $message): array => [
                    'id' => $message->id,
                    'text' => $message->text,
                    'authorName' => $message->user?->name,
                    'sentAt' => $message->created_at?->toIso8601String() ?? '',
                    'forwardedAt' => $message->forwarded_at?->toIso8601String(),
                ])->all()),
            ])->all()),
            'subThreads' => self::subThreads($thread),
            'runningCount' => $running,
        ];
    }

    /** The most running agents the short list carries; `runningCount` always counts all of them. */
    public const int RUNNING_LIST_LIMIT = 20;

    /**
     * The running agents in short, for the app: the count the Agents panel shows and the first few agents, oldest first.
     * `state` is `stopping` once a stop was asked for and the agent has not finished yet.
     *
     * @return RunningAgents
     */
    public static function running(Thread $thread): array
    {
        $query = ThreadAgent::query()->where('thread_id', $thread->id)->running();
        $count = (clone $query)->count();
        $running = [];

        foreach ($query->orderBy('id')->limit(self::RUNNING_LIST_LIMIT)->get() as $agent) {
            $running[] = [
                'id' => $agent->task_id,
                'name' => $agent->label(),
                'state' => $agent->stop_requested_at === null ? 'running' : 'stopping',
                'startedAt' => $agent->started_at?->toIso8601String(),
            ];
        }

        return ['runningCount' => $count, 'running' => $running];
    }

    /**
     * One agent without its messages: the payload of the `agent.*` thread events.
     *
     * @return AgentRow
     */
    public static function row(ThreadAgent $agent): array
    {
        return [
            'id' => $agent->task_id,
            'kind' => $agent->kind->value,
            'parentTaskId' => $agent->parent_task_id,
            'toolUseId' => $agent->tool_use_id,
            'depth' => $agent->depth,
            'agentType' => $agent->agent_type,
            'description' => $agent->description,
            'prompt' => $agent->prompt,
            'workflowName' => $agent->workflow_name,
            'model' => $agent->model,
            'isBackground' => $agent->is_background,
            'status' => $agent->status->value,
            'lastTool' => $agent->last_tool,
            'lastToolTarget' => $agent->last_tool_target,
            'summary' => $agent->summary,
            'totalTokens' => (int) $agent->total_tokens,
            'toolUses' => $agent->tool_uses,
            'durationMs' => (int) $agent->duration_ms,
            'startedAt' => $agent->started_at?->toIso8601String(),
            'finishedAt' => $agent->finished_at?->toIso8601String(),
            'stopRequestedAt' => $agent->stop_requested_at?->toIso8601String(),
            'stopError' => $agent->stop_error,
            'workflowProgress' => $agent->workflow_progress,
            'startedByTurnId' => $agent->task_run_id,
            'followUpTurnId' => $agent->follow_up_run_id,
        ];
    }

    /**
     * The direct children of the thread, with their state worked out for all of them in three queries.
     *
     * @return list<SubThreadRow>
     */
    private static function subThreads(Thread $thread): array
    {
        $children = Thread::query()->where('parent_thread_id', $thread->id)->orderBy('id')->with('primaryRepository')->get();

        if ($children->isEmpty()) {
            return [];
        }

        $ids = $children->pluck('id');
        $failed = StackThreadMessage::query()->whereIn('from_thread_id', $ids)->where('kind', StackThreadMessageKind::Failure)->pluck('from_thread_id')->flip();
        $hasRuns = TaskRun::query()->whereIn('thread_id', $ids)->distinct()->pluck('thread_id')->flip();
        $active = TaskRun::query()->whereIn('thread_id', $ids)->whereIn('turn_state', ThreadTurns::ACTIVE_STATES)->distinct()->pluck('thread_id')->flip();

        $rows = [];

        foreach ($children as $child) {
            $rows[] = [
                'id' => $child->public_id,
                'name' => $child->name,
                'title' => $child->title,
                'kind' => $child->kind->value,
                'state' => StackListing::resolveState($child->isOpen(), $failed->has($child->id), $child->result_summary !== null, $child->queued_for_slot_at !== null, $child->kind === ThreadKind::Research, $hasRuns->has($child->id), $active->has($child->id)),
                'step' => $child->current_step,
                'branch' => $child->branch,
                'pullRequestUrl' => $child->primaryRepository?->pull_request_url,
            ];
        }

        return $rows;
    }
}
