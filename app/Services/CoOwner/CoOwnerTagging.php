<?php

namespace App\Services\CoOwner;

use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;

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
     *
     * @throws CoOwnerTaggingException
     */
    public function tag(YakTask $root, User $coOwner, User|string $actor): void
    {
        $this->check($root, $actor);

        $repository = $root->repository;
        $access = $repository === null ? 'error' : $this->writeAccess->check($repository, $coOwner);

        match ($access) {
            'write' => null,
            'unlinked' => throw CoOwnerTaggingException::notLinked(),
            'read' => throw CoOwnerTaggingException::noWriteAccess(),
            default => throw CoOwnerTaggingException::accessCheckFailed(),
        };

        DB::transaction(function () use ($root, $coOwner, $actor): void {
            $this->state->tagged($this->check($root, $actor, lock: true), $coOwner);
        });
    }

    /**
     * @param  User|string  $actor  the acting Yak user, or their Slack user id when no Yak user maps to it
     *
     * @throws CoOwnerTaggingException
     */
    public function decline(YakTask $root, User|string $actor): void
    {
        DB::transaction(function () use ($root, $actor): void {
            $this->state->declined($this->check($root, $actor, lock: true));
        });
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
