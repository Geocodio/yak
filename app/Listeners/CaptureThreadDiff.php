<?php

namespace App\Listeners;

use App\Enums\ThreadSandboxState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\ThreadDiffCapture;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Stores the sandbox diff when a turn ends. A sandbox that is not running keeps
 * the previous diff, and so does a thread whose other turn is still running,
 * because that turn's end captures.
 */
class CaptureThreadDiff implements ShouldBeUnique, ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly ThreadDiffCapture $capture) {}

    public function uniqueId(TurnCompleted|TurnFailed $event): string
    {
        return 'capture-thread-diff:' . $event->run->thread_id;
    }

    public function handleCompleted(TurnCompleted $event): void
    {
        $this->captureAfter($event->run);
    }

    public function handleFailed(TurnFailed $event): void
    {
        $this->captureAfter($event->run);
    }

    private function captureAfter(TaskRun $run): void
    {
        if ($run->thread_id === null) {
            return;
        }

        $thread = Thread::query()->withoutVisibilityScope()->find($run->thread_id);

        if ($thread === null || $thread->sandbox_state !== ThreadSandboxState::Running) {
            return;
        }

        if (ThreadTurns::runningTurn($thread) !== null) {
            return;
        }

        $this->capture->capture($thread);
    }
}
