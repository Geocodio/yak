<?php

namespace App\Services\Push;

use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;

/**
 * Who a push is addressed to, per event. Visibility is checked later by
 * ThreadPush, so these only answer "who cares".
 */
class PushRecipients
{
    /**
     * @return list<User>
     */
    public function forTurn(TaskRun $run): array
    {
        return $this->users([$run->driver_user_id]);
    }

    /**
     * The person who last drove the thread answers its clarification questions.
     *
     * @return list<User>
     */
    public function forClarification(Thread $thread): array
    {
        return $this->users([$this->latestDriverId($thread)]);
    }

    /**
     * @return list<User>
     */
    public function forBlockedThread(Thread $thread): array
    {
        return $this->users([$this->latestDriverId($thread), $thread->effectiveOwnerUserId()]);
    }

    /**
     * @return list<User>
     */
    public function forHeldEvent(Thread $thread): array
    {
        return $this->users([$thread->effectiveOwnerUserId()]);
    }

    /**
     * The parent's latest driver when set, otherwise the root owner.
     *
     * @return list<User>
     */
    public function forChildEscalation(Thread $child): array
    {
        $parent = $child->parent;
        $driverId = $parent === null ? null : $this->latestDriverId($parent);

        return $this->users([$driverId ?? $child->effectiveOwnerUserId()]);
    }

    /**
     * @return list<User>
     */
    public function forPullRequest(Thread $thread): array
    {
        return $this->users([$thread->effectiveOwnerUserId()]);
    }

    /**
     * @return list<User>
     */
    public function forStack(Thread $root): array
    {
        return $this->users([$root->rootThread()->owner_user_id]);
    }

    private function latestDriverId(Thread $thread): ?int
    {
        return $thread->runs()->whereNotNull('driver_user_id')->latest('id')->value('driver_user_id');
    }

    /**
     * @param  array<int|null>  $ids
     * @return list<User>
     */
    private function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : array_values(User::whereKey($ids)->get()->all());
    }
}
