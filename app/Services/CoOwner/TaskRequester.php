<?php

namespace App\Services\CoOwner;

use App\Models\User;
use App\Models\YakTask;

/** The Yak user who asked for a task, or null when nobody maps to it. */
class TaskRequester
{
    public function __construct(private SlackUserResolver $slackUsers) {}

    /** A conversation is judged by its original requester, so follow-ups inherit the root's user. */
    public function for(YakTask $task): ?User
    {
        if ($task->startedBy !== null) {
            return $task->startedBy;
        }

        if ($task->slack_user_id !== null && $task->slack_user_id !== '') {
            return $this->slackUsers->userFor($task->slack_user_id);
        }

        return null;
    }

    /**
     * Whether a viewer is the root's requester: the user who started it, else
     * the Slack user who asked, else the user the requester maps to.
     */
    public function isRequester(YakTask $root, ?User $user, ?string $slackUserId): bool
    {
        if ($root->startedBy !== null) {
            return $root->startedBy->is($user);
        }

        if ($slackUserId !== null && $slackUserId !== '' && $slackUserId === $root->slack_user_id) {
            return true;
        }

        return $this->for($root)?->is($user) === true;
    }
}
