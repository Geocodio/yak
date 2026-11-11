<?php

namespace App\Jobs\Threads;

use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\PullRequests\RepositoryTurnPrompts;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Resets one repo of a thread to its pushed branch and starts a turn to fix
 * its CI failure. A thread runs one turn at a time, so a second repo's retry
 * waits for the first to finish.
 */
class RetryRepositoryCiJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly int $threadRepositoryId, public readonly ?string $output = null)
    {
        $this->onQueue('yak-claude');
    }

    /**
     * One queued or running retry per repo row, so a double click or an
     * automatic retry already in flight absorbs the duplicate.
     */
    public function uniqueId(): string
    {
        return (string) $this->threadRepositoryId;
    }

    public function uniqueFor(): int
    {
        return 21600;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(ThreadTurns $turns, ThreadSandbox $sandbox, SandboxManager $sandboxes): void
    {
        $row = ThreadRepository::query()->find($this->threadRepositoryId);
        $thread = $row === null ? null : Thread::query()->withoutGlobalScopes()->find($row->thread_id);
        $headTask = $thread === null ? null : YakTask::query()->where('thread_id', $thread->id)->latest('id')->first();

        if ($row === null || $thread === null || $headTask === null || $row->branch === null || ! $row->isChangesRepo()) {
            return;
        }

        if (ThreadTurns::activeTurn($thread) !== null) {
            $this->release(30);

            return;
        }

        if (! $sandbox->ensureAwake($thread)->ready) {
            $this->release(60);

            return;
        }

        $branch = escapeshellarg($row->branch);
        $sandboxes->exec($thread, ['bash', '-lc', 'cd ' . escapeshellarg($row->sandbox_path) . " && git fetch origin {$branch} && git reset --hard origin/{$branch} && git clean -fd"]);

        try {
            $turns->start($headTask, RepositoryTurnPrompts::ciFailure($row, $this->output), null, null);
        } catch (ThreadBusy) {
            $this->release(30);
        }
    }
}
