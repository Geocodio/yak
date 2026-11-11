<?php

namespace App\Services\Threads\PullRequests;

use App\Enums\RepositoryRole;
use App\Enums\ThreadStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;

/**
 * Maps a pull request event (repo full name, PR number, branch) to the
 * thread repository row it belongs to. A repo's PR numbers are only unique
 * within that repo, so the repo is always part of the match.
 */
class ThreadPullRequestRouter
{
    public function route(string $repoFullName, ?int $prNumber, ?string $branch): ?RoutedPullRequest
    {
        $slug = Repository::resolveFromGitHub(null, $repoFullName)?->slug;

        if ($slug === null) {
            return null;
        }

        $rows = ThreadRepository::query()->where('repo', $slug);

        $row = null;

        if ($prNumber !== null) {
            $row = (clone $rows)->where('pull_request_number', $prNumber)->latest('id')->first()
                ?? (clone $rows)->where('pull_request_url', 'like', '%/pull/' . $prNumber)->latest('id')->first();
        }

        if ($row === null && $branch !== null && $branch !== '') {
            $row = (clone $rows)
                ->where('role', '!=', RepositoryRole::Context->value)
                ->where('branch', $branch)
                ->whereIn('thread_id', Thread::query()->withoutGlobalScopes()->where('status', ThreadStatus::Open)->select('id'))
                ->latest('id')
                ->first();
        }

        if ($row === null) {
            return null;
        }

        $thread = Thread::query()->withoutGlobalScopes()->find($row->thread_id);

        if ($thread === null) {
            return null;
        }

        return new RoutedPullRequest(
            row: $row,
            thread: $thread,
            isPrimary: $row->role === RepositoryRole::Primary,
            headTask: YakTask::query()->where('thread_id', $thread->id)->latest('id')->first(),
        );
    }
}
