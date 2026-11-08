<?php

namespace App\Console\Commands;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\ThreadCloser;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:thread-sandboxes')]
#[Description('Stop idle thread sandboxes, close finished or idle threads and release turns waiting for a sandbox')]
class ThreadSandboxesCommand extends Command
{
    public function handle(ThreadSandbox $sandbox, ThreadCloser $closer): int
    {
        Thread::query()
            ->where('status', ThreadStatus::Open)
            ->where('sandbox_state', ThreadSandboxState::Paused)
            ->where('sandbox_state_changed_at', '<=', now()->subMinutes((int) config('yak.threads.pause_to_stop_minutes')))
            ->each(fn (Thread $thread) => $sandbox->stop($thread));

        $idleCutoff = now()->subDays((int) config('yak.threads.destroy_days'));
        $followUpCutoff = now()->subDays((int) config('yak.clarification_ttl_days'));

        Thread::query()
            ->where('status', ThreadStatus::Open)
            ->each(function (Thread $thread) use ($closer, $idleCutoff, $followUpCutoff): void {
                if ($thread->runningTurn() !== null) {
                    return;
                }

                $lastActivity = $thread->last_activity_at ?? $thread->created_at;

                if ($lastActivity <= $idleCutoff) {
                    $closer->close($thread, 'idle');

                    return;
                }

                if ($lastActivity <= $followUpCutoff && $this->isFinishedWithoutPullRequest($thread)) {
                    $closer->close($thread, 'finished');
                }
            });

        $awake = Thread::query()
            ->whereIn('sandbox_state', [ThreadSandboxState::Starting, ThreadSandboxState::Running, ThreadSandboxState::Paused])
            ->count();

        if ($awake < (int) config('yak.threads.awake_cap')) {
            Thread::query()
                ->where('blocked_reason', ThreadBlockReason::WaitingForSandbox)
                ->update(['blocked_reason' => null]);
        }

        return self::SUCCESS;
    }

    private function isFinishedWithoutPullRequest(Thread $thread): bool
    {
        $tasks = $thread->tasks;

        return $tasks->isNotEmpty()
            && $tasks->every(fn ($task): bool => $task->status->isFinal() && $task->pr_url === null);
    }
}
