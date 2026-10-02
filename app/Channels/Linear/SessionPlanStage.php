<?php

namespace App\Channels\Linear;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\YakTask;

/**
 * Where a fix task stands in its Linear agent session plan.
 */
enum SessionPlanStage: string
{
    case Working = 'working';
    case AwaitingCi = 'awaiting_ci';
    case PullRequestOpened = 'pull_request_opened';
    case Answered = 'answered';
    case Stopped = 'stopped';

    /**
     * Derive the stage from the task's current status. A `Result`
     * notification on a successful task without a pull request means the agent
     * answered without code changes; the pull request path sets its own stage,
     * so a `Result` on a task with a pull request leaves the plan as it is.
     * Other notifications on a successful task are post-completion notices
     * that leave the plan as it is.
     */
    public static function forTask(YakTask $task, ?NotificationType $type = null): ?self
    {
        return match ($task->status) {
            TaskStatus::Pending, TaskStatus::Running, TaskStatus::AwaitingClarification => self::Working,
            TaskStatus::AwaitingCi, TaskStatus::Retrying => self::AwaitingCi,
            TaskStatus::Success => $type === NotificationType::Result && $task->pr_url === null ? self::Answered : null,
            TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled => self::Stopped,
        };
    }
}
