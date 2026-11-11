<?php

namespace App\Services\Push;

use App\Enums\ThreadOwnerSide;
use App\Models\YakTask;

/**
 * Pull request and CI pushes for a task's thread. Tasks without a thread push
 * nothing, and a device-owned thread is told through HeldEvents instead.
 */
class PullRequestPush
{
    public function __construct(private readonly ThreadPush $push, private readonly PushRecipients $recipients) {}

    public function opened(YakTask $task): void
    {
        $this->send($task, 'opened', 'Yak opened a pull request.');
    }

    public function ciFailed(YakTask $task): void
    {
        $this->send($task, 'ci-failed', 'CI failed on the pull request.');
    }

    public function ciPassed(YakTask $task): void
    {
        $this->send($task, 'ci-passed', 'CI passed on the pull request.');
    }

    private function send(YakTask $task, string $kind, string $body): void
    {
        $thread = $task->thread;

        if ($thread === null || $thread->owner_side === ThreadOwnerSide::Device) {
            return;
        }

        $this->push->send(PushEvent::PullRequest, $thread, $this->recipients->forPullRequest($thread), $body, "task-{$task->id}-{$kind}");
    }
}
