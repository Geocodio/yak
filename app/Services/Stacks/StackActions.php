<?php

namespace App\Services\Stacks;

use App\Enums\ThreadKind;
use App\Exceptions\StackRuleViolation;
use App\Jobs\StartChildThreadJob;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The actions of a stack that a person starts, for the web and for the app. Each one takes
 * its lock, checks for a repeat and refuses with a typed exception.
 */
class StackActions
{
    public function __construct(private readonly ChildSpawner $spawner) {}

    /**
     * Adds a sub-thread. A repeat with the same key returns the same child and creates nothing;
     * it only starts a child whose first start was lost.
     */
    public function addChild(Thread $parent, User $actor, string $name, string $brief, ThreadKind $kind, ?string $model, ?string $spawnKey): Thread
    {
        try {
            $child = $this->spawner->spawn($parent, $name, $brief, $kind, $model, actor: $actor, spawnKey: $spawnKey);
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
            $this->dispatchStart($child);
        }

        return $child;
    }

    /**
     * The pending dispatch takes the job's unique lock before it pushes. When the push throws,
     * nothing else frees that lock, so it is released here and the client's retry can dispatch again.
     */
    private function dispatchStart(Thread $child): void
    {
        $job = new StartChildThreadJob($child->id);

        try {
            dispatch($job)->afterCommit();
        } catch (Throwable $exception) {
            (new UniqueLock(app(CacheRepository::class)))->release($job);

            throw $exception;
        }
    }

    private function needsStart(Thread $child): bool
    {
        return $child->isOpen() && $child->queued_for_slot_at === null && ! $child->runs()->exists();
    }
}
