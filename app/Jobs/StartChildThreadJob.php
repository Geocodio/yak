<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\Stacks\ChildSpawner;
use App\Services\Threads\ThreadBusy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a child thread's sandbox and starts its first turn, off the tool
 * call that spawned it. A child that must wait for a sandbox or a busy thread
 * is released for 30 seconds and tried again. Only one job per child is
 * pending at a time, so a repeated add cannot start the same sandbox twice.
 *
 * The unique lock is held for as long as the job is pending and expires after
 * uniqueFor() seconds. A dispatch dropped because a job is still pending loses
 * nothing: that job re-checks the child every 30 seconds. ChildSpawner::start
 * holds a per-child claim, so two jobs, or a job and a freed slot, never build
 * the same sandbox twice even after the unique lock expired.
 */
class StartChildThreadJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 60;

    public function __construct(public readonly int $childThreadId) {}

    public function uniqueFor(): int
    {
        return 300;
    }

    public function uniqueId(): string
    {
        return (string) $this->childThreadId;
    }

    public function handle(ChildSpawner $spawner): void
    {
        $child = Thread::query()->withoutGlobalScopes()->find($this->childThreadId);

        if ($child === null || ! $child->isOpen() || $child->rootThread()->stack_stopped_at !== null) {
            return;
        }

        try {
            $spawner->start($child);
        } catch (ThreadBusy) {
            $this->release(30);

            return;
        }

        if ($child->fresh()?->queued_for_slot_at !== null) {
            $this->release(30);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('A child thread could not start', ['thread' => $this->childThreadId, 'error' => $exception->getMessage()]);
    }
}
