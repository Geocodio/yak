<?php

namespace App\Services\Threads\PullRequests;

use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\ThreadCloser;
use Carbon\CarbonInterface;

/**
 * Keeps the per-repo pull request state on `thread_repositories` current and
 * closes a thread once every one of its pull requests has ended.
 */
class ThreadPullRequestSync
{
    public function __construct(private readonly ThreadCloser $closer) {}

    public function recordOpened(ThreadRepository $row, int $number, string $url, string $title): void
    {
        $row->forceFill([
            'pull_request_number' => $number,
            'pull_request_url' => $url,
            'pull_request_title' => $title,
            'pull_request_state' => 'open',
            'pull_request_closed_at' => null,
        ])->save();
    }

    public function recordOutcome(ThreadRepository $row, bool $merged, ?CarbonInterface $at = null): void
    {
        $row->forceFill([
            'pull_request_state' => $merged ? 'merged' : 'closed',
            'pull_request_closed_at' => $row->pull_request_closed_at ?? $at ?? now(),
        ])->save();
    }

    /**
     * Context rows never carry a PR and are ignored. The reason is
     * `pr_merged` when at least one pull request merged, else `pr_closed`.
     */
    public function closeThreadIfAllEnded(Thread $thread): void
    {
        $states = ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('role', '!=', 'context')
            ->whereNotNull('pull_request_state')
            ->pluck('pull_request_state');

        if ($states->isEmpty() || $states->contains('open')) {
            return;
        }

        $this->closer->close($thread, $states->contains('merged') ? 'pr_merged' : 'pr_closed');
    }
}
