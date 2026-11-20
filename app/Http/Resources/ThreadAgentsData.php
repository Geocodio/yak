<?php

namespace App\Http\Resources;

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadAgentMessage;
use App\Services\Threads\ThreadTurns;

/**
 * The Agents panel of a thread in one tree: Claude itself (the root), the agents and workflows it
 * started inside this checkout, and the sub-threads (separate threads with their own branch). Agents
 * come flat with `parentTaskId` and `depth`; the page builds the nesting. The array shapes here are
 * the source of truth for `resources/js/types/thread-agents.ts`.
 *
 * @phpstan-type AgentRow array{id: string, kind: string, parentTaskId: ?string, toolUseId: ?string, depth: int, agentType: ?string, description: ?string, workflowName: ?string, model: ?string, isBackground: bool, status: string, lastTool: ?string, summary: ?string, totalTokens: int, toolUses: int, durationMs: int, startedAt: ?string, finishedAt: ?string, stopRequestedAt: ?string, stopError: ?string, workflowProgress: ?list<array<string, mixed>>, startedByTurnId: ?int, followUpTurnId: ?int}
 * @phpstan-type AgentRowWithMessages array{id: string, kind: string, parentTaskId: ?string, toolUseId: ?string, depth: int, agentType: ?string, description: ?string, workflowName: ?string, model: ?string, isBackground: bool, status: string, lastTool: ?string, summary: ?string, totalTokens: int, toolUses: int, durationMs: int, startedAt: ?string, finishedAt: ?string, stopRequestedAt: ?string, stopError: ?string, workflowProgress: ?list<array<string, mixed>>, startedByTurnId: ?int, followUpTurnId: ?int, messages: list<AgentMessageRow>}
 * @phpstan-type AgentMessageRow array{id: int, text: string, authorName: ?string, sentAt: string, forwardedAt: ?string}
 * @phpstan-type SubThreadRow array{id: string, name: string, title: string, kind: string, state: 'queued'|'working'|'waiting'|'done'|'failed'|'closed', step: ?string, branch: ?string, pullRequestUrl: ?string}
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
            'workflowName' => $agent->workflow_name,
            'model' => $agent->model,
            'isBackground' => $agent->is_background,
            'status' => $agent->status->value,
            'lastTool' => $agent->last_tool,
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
                'state' => match (true) {
                    ! $child->isOpen() => 'closed',
                    $failed->has($child->id) => 'failed',
                    $child->result_summary !== null => 'done',
                    $child->queued_for_slot_at !== null || ! $hasRuns->has($child->id) => 'queued',
                    $active->has($child->id) => 'working',
                    default => 'waiting',
                },
                'step' => $child->current_step,
                'branch' => $child->branch,
                'pullRequestUrl' => $child->primaryRepository?->pull_request_url,
            ];
        }

        return $rows;
    }
}
