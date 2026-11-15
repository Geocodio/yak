<?php

namespace App\Services\Threads\PullRequests;

use App\Http\Resources\ThreadDiffData;
use App\Http\Resources\ThreadPageData;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;

/**
 * Whether Open PR applies to a thread for a person, and (later) opening it once.
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
}
