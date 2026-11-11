<?php

namespace App\Services\Threads\PullRequests;

use App\Enums\HeldEventKind;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Jobs\Threads\OpenRepositoryPullRequestJob;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Teleport\HeldEvents;
use Illuminate\Support\Str;

/**
 * Acts on a CI result for one repo of a thread. Each repo has its own retry
 * budget (`ci_retry_count`), so one repo failing never spends another's.
 */
class RepositoryCiRetry
{
    public function __construct(private readonly HeldEvents $heldEvents) {}

    /**
     * @param  list<array{name: string, state: string, durationSeconds?: ?int, excerpt?: ?string}>|null  $checks
     */
    public function handleResult(ThreadRepository $row, bool $passed, ?string $output, ?string $commitSha, ?array $checks = null): void
    {
        if (! $row->isChangesRepo()) {
            return;
        }

        if ($checks !== null) {
            $row->forceFill(['ci_checks' => $checks])->save();
        }

        $headTask = YakTask::query()->where('thread_id', $row->thread_id)->latest('id')->first();

        if (! $passed && $headTask !== null && $this->heldEvents->holdIfDeviceOwned(
            $headTask,
            HeldEventKind::CiFailed,
            'CI failed on ' . RepositoryTurnPrompts::pullRequestReference($row),
            Str::limit((string) $output, 500),
            ['commit_sha' => $commitSha, 'repo' => $row->repo],
            $row->pull_request_url,
        )) {
            return;
        }

        if ($passed) {
            $row->forceFill(['ci_status' => 'passing', 'last_commit_sha' => $commitSha ?? $row->last_commit_sha])->save();

            if ($row->pull_request_url === null) {
                OpenRepositoryPullRequestJob::dispatch($row->id);
            }

            return;
        }

        $row->forceFill(['ci_status' => 'failing', 'last_commit_sha' => $commitSha ?? $row->last_commit_sha])->save();

        if ($row->ci_retry_count < (int) config('yak.max_attempts')) {
            $row->increment('ci_retry_count');
            RetryRepositoryCiJob::dispatch($row->id, $output);

            return;
        }

        $this->notifyRetriesExhausted($row, $headTask);
    }

    private function notifyRetriesExhausted(ThreadRepository $row, ?YakTask $headTask): void
    {
        if ($headTask === null) {
            return;
        }

        $thread = Thread::query()->withoutGlobalScopes()->find($row->thread_id);
        $message = $thread === null || $thread->isPrivate()
            ? 'CI is still failing on a pull request after the retry limit.'
            : 'CI is still failing on ' . RepositoryTurnPrompts::pullRequestReference($row) . ' after the retry limit.';

        SendNotificationJob::dispatch($headTask, NotificationType::Error, $message);
    }
}
