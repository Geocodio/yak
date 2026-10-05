<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Log;

/**
 * Decides who gets a Slack direct message about a task event. Slack tasks
 * reach people through thread mentions and GitHub tasks through the pull
 * request, so only Linear, dashboard and automated tasks send direct
 * messages. The starter hears about every event that needs a person,
 * including the result of a follow-up on their task. A different responsible
 * user hears only once, when the pull request is first ready.
 */
class DirectMessageRecipients
{
    private const array STARTER_SOURCES = ['linear', 'dashboard'];

    private const array RESPONSIBLE_SOURCES = ['linear', 'dashboard', 'sentry', 'flaky-test'];

    private const array PUSHING_TYPES = [
        NotificationType::Clarification,
        NotificationType::Reminder,
        NotificationType::Result,
        NotificationType::Error,
        NotificationType::Cancelled,
    ];

    /**
     * @return list<User>
     */
    public function for(YakTask $task, NotificationType $type): array
    {
        if (! in_array($type, self::PUSHING_TYPES, true)) {
            return [];
        }

        $recipients = [];

        $starter = in_array($task->source, self::STARTER_SOURCES, true) ? $task->startedBy : null;

        if ($starter !== null) {
            $recipients[] = $starter;
        }

        $responsible = in_array($task->source, self::RESPONSIBLE_SOURCES, true) ? $task->responsibleUser : null;

        if ($responsible !== null && $this->isPullRequestReady($task, $type) && ! $responsible->is($starter)) {
            $recipients[] = $responsible;
        }

        return array_values(array_filter($recipients, function (User $user) use ($task): bool {
            if ($user->direct_messages_enabled) {
                return true;
            }

            Log::channel('yak')->info('Direct message skipped, turned off by the user', ['user_id' => $user->id, 'task_id' => $task->id]);

            return false;
        }));
    }

    private function isPullRequestReady(YakTask $task, NotificationType $type): bool
    {
        return $type === NotificationType::Result
            && $task->pr_url !== null
            && $task->parent_task_id === null
            && $task->mode !== TaskMode::Setup;
    }
}
