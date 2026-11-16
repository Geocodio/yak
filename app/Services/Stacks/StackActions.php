<?php

namespace App\Services\Stacks;

use App\Enums\ThreadKind;
use App\Enums\ThreadStatus;
use App\Exceptions\StackRuleViolation;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\DeliverStackNoteJob;
use App\Jobs\StopStackJob;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\ShortLockWait;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The actions of a stack that a person starts, for the web and for the app. Each one takes
 * its lock, checks for a repeat and refuses with a typed exception.
 */
class StackActions
{
    public function __construct(
        private readonly ChildSpawner $spawner,
        private readonly StopStack $stopStack,
        private readonly ChildAwakeLimit $awakeLimit,
        private readonly ChildOverrides $overrides,
    ) {}

    /**
     * Adds a sub-thread. A repeat with the same key returns the same child and creates nothing;
     * it only starts a child whose first start was lost.
     */
    public function addChild(Thread $parent, User $actor, string $name, string $brief, ThreadKind $kind, ?string $model, ?string $spawnKey, ?string $effort = null): Thread
    {
        try {
            $child = $this->spawner->spawn($parent, $name, $brief, $kind, $model, actor: $actor, spawnKey: $spawnKey, effort: $effort);
        } catch (StackRuleViolation $violation) {
            if ($violation->errorCode !== null) {
                throw $violation->toRefusal();
            }

            if ($violation->field === null) {
                Log::warning('A sub-thread refusal had neither a code nor a field', ['reason' => $violation->getMessage()]);
            }

            throw $violation->toValidationException();
        }

        if ($child->wasRecentlyCreated || $this->needsStart($child)) {
            $this->spawner->requestStart($child);
        }

        return $child;
    }

    /**
     * Stamps the stack as stopped and queues the stop. A repeat queues it again only while a turn still runs.
     * The job is pushed after the stamp commits, so it never runs before the stamp is visible. A failed push
     * leaves the stamp, and a repeat queues the stop again while a turn still runs.
     */
    public function stop(Thread $thread): void
    {
        $this->withRootLock($thread, function (Thread $root): void {
            if ($root->stack_stopped_at === null) {
                $root->forceFill(['stack_stopped_at' => now()])->save();
                StopStackJob::dispatch($root->id)->afterCommit();

                return;
            }

            if ($this->stopStack->hasActiveTurn($root)) {
                StopStackJob::dispatch($root->id)->afterCommit();
            }
        });
    }

    /**
     * Saves the limit and queues a start for each waiting child when a slot is free.
     */
    public function setLimit(Thread $thread, int $limit): void
    {
        $queued = [];

        $this->withRootLock($thread, function (Thread $root) use ($limit, &$queued): void {
            $this->awakeLimit->set($root, $limit);

            if ($root->stack_stopped_at !== null || ! $this->awakeLimit->hasFreeSlot($root)) {
                return;
            }

            $queued = Thread::query()->withoutGlobalScopes()
                ->where('parent_thread_id', $root->id)
                ->whereNotNull('queued_for_slot_at')
                ->where('status', ThreadStatus::Open->value)
                ->orderBy('queued_for_slot_at')
                ->orderBy('id')
                ->get()
                ->all();
        });

        // Pushed after the lock is released, so a failed push cannot leave a claim behind a commit.
        foreach ($queued as $child) {
            $this->spawner->requestStart($child);
        }
    }

    public function takeOver(Thread $child, User $actor): void
    {
        $this->withRootLock($child, function () use ($child, $actor): void {
            $child = $this->freshChild($child);

            if ($child->taken_over_at !== null) {
                return;
            }

            $this->assertFailedChild($child);
            $this->overrides->takeOver($child, $actor);
        });
    }

    public function split(Thread $child, User $actor): void
    {
        $this->withRootLock($child, function () use ($child, $actor): void {
            $child = $this->freshChild($child);

            if ($child->split_requested_at !== null) {
                $this->redeliverNotes($child);

                return;
            }

            $this->assertFailedChild($child);

            $parent = Thread::query()->withoutGlobalScopes()->findOrFail($child->parent_thread_id);

            if (! $parent->isOpen()) {
                throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'The parent thread is closed, so it cannot split this work.');
            }

            $this->assertNotTakenOver($child);
            $child->forceFill(['split_requested_at' => now()])->save();
            $this->overrides->split($child, $actor);
        });
    }

    public function drop(Thread $child, User $actor): void
    {
        $this->withRootLock($child, function () use ($child, $actor): void {
            $child = $this->freshChild($child);

            if (! $child->isOpen() && $child->close_reason === 'dropped') {
                $this->redeliverNotes($child);

                return;
            }

            $this->assertFailedChild($child);
            $this->assertNotTakenOver($child);
            $this->overrides->drop($child, $actor);
        });
    }

    /**
     * Runs the callback inside the root thread's row lock, the one lock every stack action takes.
     *
     * @param  Closure(Thread): void  $callback
     */
    private function withRootLock(Thread $thread, Closure $callback): void
    {
        $rootId = $thread->rootThread()->id;

        ShortLockWait::threadTransaction(function () use ($rootId, $callback): void {
            $callback(Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($rootId));
        });
    }

    private function freshChild(Thread $child): Thread
    {
        return Thread::query()->withoutGlobalScopes()->findOrFail($child->id);
    }

    private function assertFailedChild(Thread $child): void
    {
        if (! $child->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This sub-thread is closed.');
        }

        $state = StackListing::state($child);

        if ($state !== 'failed') {
            throw ThreadActionRefused::because(ThreadActionRefused::CHILD_NOT_FAILED, 'Only a sub-thread that failed can be taken over, split or dropped.', ['state' => $state]);
        }
    }

    private function assertNotTakenOver(Thread $child): void
    {
        if ($child->taken_over_at !== null) {
            throw ThreadActionRefused::because(ThreadActionRefused::CHILD_NOT_FAILED, 'You drive this sub-thread now, so it is no longer part of the stack\'s work.', ['state' => 'failed', 'takenOver' => true]);
        }
    }

    /**
     * The notes of a person about this child that no job has delivered yet.
     *
     * @return Collection<int, StackThreadMessage>
     */
    public function undeliveredNotes(Thread $child): Collection
    {
        return StackThreadMessage::query()
            ->where('from_thread_id', $child->id)
            ->whereNotNull('from_user_id')
            ->whereNull('delivered_at')
            ->get();
    }

    private function redeliverNotes(Thread $child): void
    {
        foreach ($this->undeliveredNotes($child) as $note) {
            DeliverStackNoteJob::dispatch($note->id)->afterCommit();
        }
    }

    private function needsStart(Thread $child): bool
    {
        return $child->isOpen() && $child->queued_for_slot_at === null && ! $child->runs()->exists();
    }
}
