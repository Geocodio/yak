<?php

namespace App\Services\CoOwner;

use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Devices\ShortLockWait;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Lets the requester tag a co-owner, or decline to, before the gate holds the
 * change. Both run under a row lock on the conversation root and refuse once
 * the conversation has a co-owner request or the head task is held or finished.
 */
class CoOwnerTagging
{
    public function __construct(
        private TaskRequester $requesters,
        private GitHubWriteAccess $writeAccess,
        private SlackUserResolver $slackUsers,
        private EarlyWarningState $state,
    ) {}

    /**
     * @param  User|string  $actor  the acting Yak user, or their Slack user id when no Yak user maps to it
     * @param  Closure(YakTask): void|null  $guard  runs under the row lock and throws to refuse
     *
     * @throws CoOwnerTaggingException
     */
    public function tag(YakTask $root, User $coOwner, User|string $actor, bool $isTimeBounded = false, ?Closure $guard = null): void
    {
        $this->check($root, $actor);

        $repository = $root->repository;
        $access = $repository === null ? 'error' : $this->writeAccess->check($repository, $coOwner, $isTimeBounded);

        match ($access) {
            'write' => null,
            'unlinked' => throw CoOwnerTaggingException::notLinked(),
            'read' => throw CoOwnerTaggingException::noWriteAccess(),
            default => throw CoOwnerTaggingException::accessCheckFailed($coOwner->name),
        };

        $this->locked(function () use ($root, $coOwner, $actor, $guard): void {
            $locked = $this->check($root, $actor, lock: true);
            $guard?->__invoke($locked);
            $this->state->tagged($locked, $coOwner);
        }, $isTimeBounded);
    }

    /**
     * @param  User|string  $actor  the acting Yak user, or their Slack user id when no Yak user maps to it
     * @param  Closure(YakTask): void|null  $guard  runs under the row lock and throws to refuse
     *
     * @throws CoOwnerTaggingException
     */
    public function decline(YakTask $root, User|string $actor, bool $isTimeBounded = false, ?Closure $guard = null): void
    {
        $this->locked(function () use ($root, $actor, $guard): void {
            $locked = $this->check($root, $actor, lock: true);
            $guard?->__invoke($locked);
            $this->state->declined($locked);
        }, $isTimeBounded);
    }

    /**
     * A time-bounded lock section waits at most five seconds and then answers 503.
     *
     * @param  Closure(): void  $section
     */
    private function locked(Closure $section, bool $isTimeBounded): void
    {
        if ($isTimeBounded) {
            ShortLockWait::transaction($section, fn (): Throwable => new HttpException(503, 'Yak is still handling another request for this task. Try again in a moment.'));

            return;
        }

        DB::transaction($section);
    }

    /** Refuses unless the actor is the requester and the conversation is still undecided. */
    private function check(YakTask $root, User|string $actor, bool $lock = false): YakTask
    {
        $query = YakTask::whereKey($root->id);
        $locked = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $user = $actor instanceof User ? $actor : $this->slackUsers->userFor($actor);
        $slackUserId = is_string($actor) ? $actor : $actor->slack_user_id;

        if (! $this->requesters->isRequester($locked, $user, $slackUserId)) {
            throw CoOwnerTaggingException::notRequester();
        }

        $conversation = $locked->conversation();
        $head = $conversation->last();

        if ($head === null
            || $head->status === TaskStatus::AwaitingCoOwner
            || $head->status->isFinal()
            || CoOwnerRequest::whereIn('task_id', $conversation->pluck('id'))->exists()) {
            throw CoOwnerTaggingException::alreadyDecided();
        }

        return $locked;
    }
}
