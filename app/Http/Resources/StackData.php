<?php

namespace App\Http\Resources;

use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\StackListing;
use App\Services\Stacks\StackUsage;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTurns;
use App\Support\SecretRedactor;
use Illuminate\Support\Str;

/**
 * Body of `threads.stack.show`. The array shape below is the source of truth
 * for `resources/js/types/stack.ts`.
 */
final class StackData
{
    private const MESSAGE_LIMIT = 50;

    private const TEXT_LIMIT = 500;

    /**
     * @return array{
     *     rootId: string,
     *     isRoot: bool,
     *     parent: ?array{id: string, name: string, title: string},
     *     children: list<array{id: string, name: string, title: string, kind: string, model: ?string, state: string, step: ?string, resultSummary: ?string, branch: ?string, repo: ?string, pullRequestUrl: ?string, ciStatus: ?string, filesChanged: ?int, queued: bool, messagesPaused: bool, takenOver: bool, canOverride: bool}>,
     *     limit: array{current: int, max: int, awake: int, waiting: int},
     *     maxChildren: int,
     *     usage: array{turnCount: int, costUsd: float, activeHours: float, note: string},
     *     messages: list<array{id: int, fromName: string, toName: string, kind: string, text: string, createdAt: string}>,
     *     canDrive: bool,
     *     canStop: bool,
     * }
     */
    public static function for(Thread $thread, ?User $viewer): array
    {
        $access = app(ThreadAccess::class);
        $root = $thread->rootThread();
        $parent = $thread->isChild() ? Thread::query()->withoutGlobalScopes()->find($thread->parent_thread_id) : null;
        $canDrive = $access->canDrive($viewer, $root);

        $children = [];
        $awake = ThreadTurns::activeTurn($root) !== null ? 1 : 0;
        $waiting = 0;

        foreach (Thread::query()->withoutVisibilityScope()->visibleTo($viewer)->where('root_thread_id', $root->id)->with(['primaryRepository', 'diff'])->orderBy('id')->get() as $child) {
            $isOtherRepo = $child->repo !== $root->repo;
            $state = StackListing::state($child);
            $awake += in_array($state, ['working', 'waiting'], true) ? 1 : 0;
            $waiting += $child->queued_for_slot_at !== null ? 1 : 0;

            $children[] = [
                'id' => $child->public_id,
                'name' => $child->name,
                'title' => $child->title,
                'kind' => $child->kind->value,
                'model' => $child->model,
                'state' => $state,
                'step' => $child->current_step,
                'resultSummary' => $child->result_summary === null ? null : self::clip($child->result_summary),
                'branch' => $child->branch,
                'repo' => $isOtherRepo ? $child->repo : null,
                'pullRequestUrl' => $isOtherRepo ? $child->primaryRepository?->pull_request_url : null,
                'ciStatus' => $isOtherRepo ? $child->primaryRepository?->ci_status : null,
                'filesChanged' => $child->diff?->files_count,
                'queued' => $child->queued_for_slot_at !== null,
                'messagesPaused' => $child->messages_paused_at !== null,
                'takenOver' => $child->taken_over_at !== null,
                'canOverride' => $canDrive && $state === 'failed' && $child->taken_over_at === null,
            ];
        }

        $usage = app(StackUsage::class)->for($root);
        $names = Thread::query()->withoutGlobalScopes()->where(fn ($query) => $query->whereKey($root->id)->orWhere('root_thread_id', $root->id))->pluck('name', 'id');

        $messages = StackThreadMessage::query()
            ->where('root_thread_id', $root->id)
            ->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (StackThreadMessage $message): array => [
                'id' => $message->id,
                'fromName' => (string) $names->get($message->from_thread_id, ''),
                'toName' => (string) $names->get($message->to_thread_id, ''),
                'kind' => $message->kind->value,
                'text' => self::clip($message->text),
                'createdAt' => $message->created_at?->toIso8601String() ?? '',
            ])
            ->all();
        $messages = array_values($messages);

        return [
            'rootId' => $root->public_id,
            'isRoot' => ! $thread->isChild(),
            'parent' => $parent === null ? null : ['id' => $parent->public_id, 'name' => $parent->name, 'title' => $parent->title],
            'children' => $children,
            'limit' => ['current' => $root->child_awake_limit, 'max' => (int) config('yak.threads.person_awake_cap'), 'awake' => $awake, 'waiting' => $waiting],
            'maxChildren' => (int) config('yak.threads.max_children'),
            'usage' => [
                'turnCount' => $usage['turnCount'],
                'costUsd' => $usage['costUsd'],
                'activeHours' => $usage['activeHours'],
                'note' => 'API-equivalent estimate. Subscription accounts are not billed per token.',
            ],
            'messages' => $messages,
            'canDrive' => $canDrive,
            'canStop' => $canDrive,
        ];
    }

    private static function clip(string $text): string
    {
        return Str::limit(SecretRedactor::redact($text), self::TEXT_LIMIT - 3, '...');
    }
}
