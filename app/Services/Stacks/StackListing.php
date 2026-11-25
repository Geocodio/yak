<?php

namespace App\Services\Stacks;

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Collection;

class StackListing
{
    /**
     * @return list<array{name: string, kind: string, state: string, latestResult: ?string, repo: ?string, pullRequestUrl: ?string, ciStatus: ?string}>
     */
    public static function for(Thread $caller): array
    {
        $rootId = $caller->root_thread_id ?? $caller->id;

        $rows = [];

        $rootRepo = Thread::query()->withoutGlobalScopes()->whereKey($rootId)->value('repo');

        foreach (Thread::query()
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_thread_id', $rootId))
            ->orderBy('id')
            ->with('primaryRepository')->get() as $thread) {
            $isOtherRepo = $thread->repo !== $rootRepo;
            $rows[] = [
                'name' => $thread->name,
                'kind' => $thread->kind->value,
                'state' => self::state($thread),
                'latestResult' => $thread->result_summary ?? $thread->current_step,
                'repo' => $isOtherRepo ? $thread->repo : null,
                'pullRequestUrl' => $isOtherRepo ? $thread->primaryRepository?->pull_request_url : null,
                'ciStatus' => $isOtherRepo ? $thread->primaryRepository?->ci_status : null,
            ];
        }

        return $rows;
    }

    /**
     * The one mapping from a sub-thread's facts to its state, for every surface. `queued` means waiting for a
     * free spot and only applies to a child with its own sandbox; a child that has not run yet is `starting`.
     *
     * @return 'closed'|'failed'|'done'|'queued'|'starting'|'working'|'waiting'
     */
    public static function resolveState(bool $isOpen, bool $hasFailed, bool $hasResult, bool $isQueuedForSlot, bool $isResearch, bool $hasRuns, bool $isActive): string
    {
        return match (true) {
            ! $isOpen => 'closed',
            $hasFailed => 'failed',
            $hasResult => 'done',
            $isQueuedForSlot && ! $isResearch => 'queued',
            ! $hasRuns => 'starting',
            $isActive => 'working',
            default => 'waiting',
        };
    }

    public static function state(Thread $thread): string
    {
        $isOpen = $thread->isOpen();
        $hasFailed = $isOpen && StackThreadMessage::query()->where('from_thread_id', $thread->id)->where('kind', StackThreadMessageKind::Failure)->exists();
        $hasRuns = $isOpen && $thread->runs()->exists();

        return self::resolveState($isOpen, $hasFailed, $thread->result_summary !== null, $thread->queued_for_slot_at !== null, $thread->kind === ThreadKind::Research, $hasRuns, $hasRuns && ThreadTurns::activeTurn($thread) !== null);
    }

    /**
     * The state of every given thread, worked out in three queries however many there are.
     *
     * @param  Collection<int, Thread>  $threads
     * @return array<int, 'closed'|'failed'|'done'|'queued'|'starting'|'working'|'waiting'> state by thread id
     */
    public static function states(Collection $threads): array
    {
        if ($threads->isEmpty()) {
            return [];
        }

        $ids = $threads->pluck('id');
        $failed = StackThreadMessage::query()->whereIn('from_thread_id', $ids)->where('kind', StackThreadMessageKind::Failure)->pluck('from_thread_id')->flip();
        $hasRuns = TaskRun::query()->whereIn('thread_id', $ids)->distinct()->pluck('thread_id')->flip();
        $active = TaskRun::query()->whereIn('thread_id', $ids)->whereIn('turn_state', ThreadTurns::ACTIVE_STATES)->distinct()->pluck('thread_id')->flip();

        /** @var array<int, 'closed'|'failed'|'done'|'queued'|'starting'|'working'|'waiting'> $states */
        $states = $threads->mapWithKeys(fn (Thread $thread): array => [$thread->id => self::resolveState($thread->isOpen(), $failed->has($thread->id), $thread->result_summary !== null, $thread->queued_for_slot_at !== null, $thread->kind === ThreadKind::Research, $hasRuns->has($thread->id), $active->has($thread->id))])->all();

        return $states;
    }
}
