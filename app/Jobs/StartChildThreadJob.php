<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\Stacks\ChildSpawner;
use App\Services\Threads\ThreadBusy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a child thread's sandbox and starts its first turn, off the tool
 * call that spawned it. A child that must wait for a sandbox or a busy thread
 * is released for 30 seconds and tried again.
 */
class StartChildThreadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 60;

    public function __construct(public readonly int $childThreadId) {}

    public function handle(ChildSpawner $spawner): void
    {
        $child = Thread::query()->withoutGlobalScopes()->find($this->childThreadId);

        if ($child === null || ! $child->isOpen() || $child->stack_stopped_at !== null) {
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
