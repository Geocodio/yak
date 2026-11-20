<?php

namespace App\Services\Threads\PullRequests;

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Devices\ShortLockWait;
use App\Services\Threads\ThreadEventPublisher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tracks a pull request the person asked for from the thread page, from the click until the
 * job opens it, holds it for a co-owner, or gives up. Each change appends a thread state event,
 * so every open tab reloads its header and tells the person how it went.
 */
class PullRequestOpening
{
    /** Longer than the job's retries and a slow co-owner judgement together. */
    private const OPENING_SECONDS = 600;

    private const FAILED_SECONDS = 3600;

    private const GITHUB_PREFIX = 'GitHub PR creation failed: ';

    /** Records and flushes on one instance: the publisher holds recorded events until its flush broadcasts them. */
    public function __construct(private ThreadEventPublisher $publisher) {}

    /**
     * Takes the opening for this thread with one conditional update, so only one request wins. A claim older than
     * the opening window counts as a job that died. The claim and its thread event commit together.
     */
    public function claim(Thread $thread, ?CarbonInterface $claimedAt = null): bool
    {
        $claimedAt ??= now();
        $isClaimed = ShortLockWait::threadTransaction(function () use ($thread, $claimedAt): bool {
            $changed = Thread::withoutGlobalScopes()
                ->whereKey($thread->id)
                ->where(fn ($query) => $query
                    ->whereNull('pull_request_opening_state')
                    ->orWhere('pull_request_opening_state', 'failed')
                    ->orWhere(fn ($stale) => $stale
                        ->where('pull_request_opening_state', 'opening')
                        ->where('pull_request_opening_at', '<', now()->subSeconds(self::OPENING_SECONDS))))
                ->update(['pull_request_opening_state' => 'opening', 'pull_request_opening_error' => null, 'pull_request_opening_at' => $claimedAt]);

            if ($changed === 1) {
                $this->publisher->recordThreadState($thread->id);
            }

            return $changed === 1;
        });

        if ($isClaimed) {
            $this->publisher->flush();
        }

        return $isClaimed;
    }

    /**
     * Gives back the claim made at $claimedAt when its job could not be queued, so a retry starts at once.
     * A claim that someone else took since is left alone.
     */
    public function release(Thread $thread, CarbonInterface $claimedAt): void
    {
        $hasReleased = ShortLockWait::threadTransaction(function () use ($thread, $claimedAt): bool {
            $changed = Thread::withoutGlobalScopes()
                ->whereKey($thread->id)
                ->where('pull_request_opening_state', 'opening')
                ->where('pull_request_opening_at', $claimedAt)
                ->update(['pull_request_opening_state' => null, 'pull_request_opening_error' => null, 'pull_request_opening_at' => null]);

            if ($changed === 1) {
                $this->publisher->recordThreadState($thread->id);
            }

            return $changed === 1;
        });

        if ($hasReleased) {
            $this->publisher->flush();
        }
    }

    /**
     * Ends the opening the person asked for; a job that runs for any other reason changes nothing here.
     */
    public function finish(YakTask $task, ?Throwable $failure = null): void
    {
        if ($task->thread_id === null) {
            return;
        }

        $threadId = $task->thread_id;
        $hasFinished = DB::transaction(function () use ($threadId, $failure): bool {
            $changed = Thread::withoutGlobalScopes()
                ->whereKey($threadId)
                ->where('pull_request_opening_state', 'opening')
                ->update($failure === null
                    ? ['pull_request_opening_state' => null, 'pull_request_opening_error' => null, 'pull_request_opening_at' => null]
                    : ['pull_request_opening_state' => 'failed', 'pull_request_opening_error' => $this->reason($failure), 'pull_request_opening_at' => now()]);

            if ($changed === 1) {
                $this->publisher->recordThreadState($threadId);
            }

            return $changed === 1;
        });

        if ($hasFinished) {
            $this->publisher->flush();
        }
    }

    /**
     * The stored part only, for the thread state event: it changes at every step the person sees.
     *
     * @return 'opening'|'failed'|null
     */
    public function flag(int $threadId): ?string
    {
        return $this->stored($threadId)['state'] ?? null;
    }

    /**
     * @return array{state: 'opening'|'held'|'failed', error: ?string, at: ?string}|null
     */
    public function stateFor(Thread $thread): ?array
    {
        $stored = $this->stored($thread->id);

        if ($stored !== null) {
            return $stored;
        }

        $task = $thread->pullRequestTask();
        $isHeld = $task !== null && CoOwnerRequest::query()
            ->where('root_task_id', $task->rootTask()->id)
            ->where('status', CoOwnerRequestStatus::Pending)
            ->exists();

        return $isHeld ? ['state' => 'held', 'error' => null, 'at' => null] : null;
    }

    /**
     * The opening kept on the thread row while it is still fresh enough to show.
     *
     * @return array{state: 'opening'|'failed', error: ?string, at: string}|null
     */
    private function stored(int $threadId): ?array
    {
        $row = DB::table('threads')->where('id', $threadId)->first(['pull_request_opening_state', 'pull_request_opening_error', 'pull_request_opening_at']);

        if ($row === null || $row->pull_request_opening_at === null || ! in_array($row->pull_request_opening_state, ['opening', 'failed'], true)) {
            return null;
        }

        $at = CarbonImmutable::parse($row->pull_request_opening_at, 'UTC');
        $window = $row->pull_request_opening_state === 'opening' ? self::OPENING_SECONDS : self::FAILED_SECONDS;

        return $at->addSeconds($window)->isPast()
            ? null
            : ['state' => $row->pull_request_opening_state, 'error' => $row->pull_request_opening_error, 'at' => $at->toIso8601String()];
    }

    private function reason(Throwable $failure): string
    {
        $message = $failure->getMessage();

        return str_starts_with($message, self::GITHUB_PREFIX)
            ? 'GitHub said: ' . substr($message, strlen(self::GITHUB_PREFIX))
            : 'Yak could not reach GitHub. Try again in a moment.';
    }
}
