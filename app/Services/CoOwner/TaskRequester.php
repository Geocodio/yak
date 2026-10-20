<?php

namespace App\Services\CoOwner;

use App\Models\User;
use App\Models\YakTask;

/** The Yak user who asked for a task, or null when nobody maps to it. */
class TaskRequester
{
    public function __construct(private SlackUserResolver $slackUsers) {}

    public function for(YakTask $task): ?User
    {
        if ($task->startedBy !== null) {
            return $task->startedBy;
        }

        if ($task->slack_user_id !== null && $task->slack_user_id !== '') {
            return $this->slackUsers->userFor($task->slack_user_id);
        }

        if ($task->source === 'github' && $task->author_name !== null && $task->author_name !== '') {
            return User::query()->whereNotNull('github_id')->where('github_login', $task->author_name)->first();
        }

        return null;
    }
}
