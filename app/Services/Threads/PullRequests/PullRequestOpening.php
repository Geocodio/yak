<?php

namespace App\Services\Threads\PullRequests;

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Cache;
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

    public function start(Thread $thread): void
    {
        Cache::put($this->key($thread->id), ['state' => 'opening', 'error' => null, 'at' => now()->toIso8601String()], self::OPENING_SECONDS);
        app(ThreadEventPublisher::class)->appendThreadState($thread->id);
    }

    /**
     * Ends the opening the person asked for; a job that runs for any other reason changes nothing here.
     */
    public function finish(YakTask $task, ?Throwable $failure = null): void
    {
        if ($task->thread_id === null || ! Cache::has($this->key($task->thread_id))) {
            return;
        }

        if ($failure === null) {
            Cache::forget($this->key($task->thread_id));
        } else {
            Cache::put($this->key($task->thread_id), ['state' => 'failed', 'error' => $this->reason($failure), 'at' => now()->toIso8601String()], self::FAILED_SECONDS);
        }

        app(ThreadEventPublisher::class)->appendThreadState($task->thread_id);
    }

    /**
     * The cached part only, for the thread state event: it changes at every step the person sees.
     *
     * @return 'opening'|'failed'|null
     */
    public function flag(int $threadId): ?string
    {
        return Cache::get($this->key($threadId))['state'] ?? null;
    }

    /**
     * @return array{state: 'opening'|'held'|'failed', error: ?string, at: ?string}|null
     */
    public function stateFor(Thread $thread): ?array
    {
        /** @var array{state: 'opening'|'failed', error: ?string, at: string}|null $cached */
        $cached = Cache::get($this->key($thread->id));

        if ($cached !== null) {
            return $cached;
        }

        $task = $thread->pullRequestTask();
        $isHeld = $task !== null && CoOwnerRequest::query()
            ->where('root_task_id', $task->rootTask()->id)
            ->where('status', CoOwnerRequestStatus::Pending)
            ->exists();

        return $isHeld ? ['state' => 'held', 'error' => null, 'at' => null] : null;
    }

    private function reason(Throwable $failure): string
    {
        $message = $failure->getMessage();

        return str_starts_with($message, self::GITHUB_PREFIX)
            ? 'GitHub said: ' . substr($message, strlen(self::GITHUB_PREFIX))
            : 'Yak could not reach GitHub. Try again in a moment.';
    }

    private function key(int $threadId): string
    {
        return "thread:{$threadId}:pull-request-opening";
    }
}
