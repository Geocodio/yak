<?php

namespace App\Services\Threads\PullRequests;

use App\Exceptions\ThreadActionRefused;
use App\Http\Resources\ThreadDiffData;
use App\Http\Resources\ThreadPageData;
use App\Jobs\CreatePullRequestJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadPublishGuard;

/**
 * Whether Open PR applies to a thread for a person, and opening it once.
 */
final class OpenPullRequest
{
    public function __construct(private readonly ThreadAccess $access, private readonly PullRequestOpening $opening) {}

    /**
     * True for a driver on an open thread that has pull-request-bound changes, no pull request and no opening running or held.
     */
    public function canOpen(Thread $thread, User $viewer): bool
    {
        if (! $this->access->canDrive($viewer, $thread) || ! $thread->isOpen() || ThreadPageData::pullRequest($thread) !== null) {
            return false;
        }

        $state = $this->opening->stateFor($thread)['state'] ?? null;

        return ($state === null || $state === 'failed') && (ThreadDiffData::totals($thread)['pullRequestFilesCount'] ?? 0) > 0;
    }

    /**
     * Opens the pull request for the thread's latest branch once: a repeat while it opens, after it opened or while a
     * co-owner holds it starts nothing. The opening is claimed on the thread row, so a cache flush never lets a
     * second job through.
     *
     * @return 'opening'|'open'|'held'
     *
     * @throws ThreadActionRefused
     */
    public function open(Thread $thread, User $user, bool $acknowledgedPrivatePublish): string
    {
        if (ThreadPageData::pullRequest($thread) !== null) {
            return 'open';
        }

        if (($this->opening->stateFor($thread)['state'] ?? null) === 'held') {
            return 'held';
        }

        ThreadPublishGuard::assertAcknowledged($thread, $acknowledgedPrivatePublish);

        // Threads started from the dashboard or by teleport keep their branch on the thread, not on the task.
        $task = $thread->pullRequestTask();
        $branch = $task->branch_name ?? $thread->branch;

        if ($task === null || blank($branch)) {
            throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_PUBLISH, 'This thread has no changes to publish yet.');
        }

        if ($task->branch_name === null) {
            $task->update(['branch_name' => $branch]);
        }

        if ($this->opening->claim($thread)) {
            CreatePullRequestJob::dispatch($task);
        }

        return 'opening';
    }
}
