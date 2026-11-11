<?php

namespace App\Services\Threads;

use App\DataTransferObjects\PreviewManifest;
use App\DataTransferObjects\SandboxWake;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use LogicException;
use Throwable;

/**
 * The thread sandbox lifecycle: wake, pause when idle, stop and destroy. Each
 * operation records the new state on the thread rows sharing the container.
 */
class ThreadSandbox
{
    private const TOUCH_THROTTLE_SECONDS = 30;

    private const LOCK_SECONDS = 600;

    public function __construct(
        private SandboxManager $sandboxes,
        private SandboxCapacity $capacity,
        private BrokerStreams $streams,
        private SessionFileSync $sessions,
    ) {}

    public function touch(Thread $thread): void
    {
        if (Cache::add("thread-sandbox:touch:{$thread->id}", true, self::TOUCH_THROTTLE_SECONDS)) {
            $thread->forceFill(['last_activity_at' => now()])->save();
        }
    }

    public function pauseIfIdle(Thread $thread): void
    {
        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $thread->refresh();

            if ($thread->sandbox_state !== ThreadSandboxState::Running || $thread->isSandboxBusy()) {
                return;
            }

            $this->sandboxes->pause($thread);
            $this->record($thread, ThreadSandboxState::Paused);
        } finally {
            $lock->release();
        }
    }

    public function stop(Thread $thread): void
    {
        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->stopLocked($thread);
        } finally {
            $lock->release();
        }
    }

    public function destroy(Thread $thread): void
    {
        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $thread->refresh();

            if ($thread->isSandboxBusy() || ! $this->exists($thread)) {
                return;
            }

            $this->sandboxes->destroy($thread);
            $this->record($thread, ThreadSandboxState::Destroyed);
        } finally {
            $lock->release();
        }
    }

    public function ensureAwake(Thread $thread): SandboxWake
    {
        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return SandboxWake::restoring();
        }

        try {
            $thread->refresh();

            return match ($thread->sandbox_state) {
                ThreadSandboxState::Running => SandboxWake::ready(),
                ThreadSandboxState::Starting => SandboxWake::restoring(),
                ThreadSandboxState::Paused => $this->resume($thread),
                ThreadSandboxState::Stopped => $this->startStopped($thread),
                default => throw new LogicException('restore arrives in chunk 4'),
            };
        } finally {
            $lock->release();
        }
    }

    private function resume(Thread $thread): SandboxWake
    {
        $this->sandboxes->resume($thread);
        $this->record($thread, ThreadSandboxState::Running);

        return SandboxWake::ready();
    }

    private function startStopped(Thread $thread): SandboxWake
    {
        while (($reason = $this->capacity->needsEviction($thread)) !== null) {
            $candidate = $this->capacity->evictionCandidate($thread, $reason);

            if ($candidate === null) {
                $thread->update(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => $thread->blocked_at ?? now()]);

                return SandboxWake::waitingForSlot();
            }

            $this->stop($candidate);
            $candidate->refresh();

            if ($candidate->sandbox_state !== ThreadSandboxState::Stopped) {
                // The candidate could not be stopped; do not loop on it.
                $thread->update(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => $thread->blocked_at ?? now()]);

                return SandboxWake::waitingForSlot();
            }
        }

        $this->sandboxes->start($thread);
        $this->record($thread, ThreadSandboxState::Running);
        $thread->update(['runner_state' => RunnerState::None]);
        $this->runColdStart($thread);

        if ($thread->blocked_reason === ThreadBlockReason::WaitingForSandbox) {
            $thread->update(['blocked_reason' => null, 'blocked_at' => null]);
        }

        return SandboxWake::ready();
    }

    private function runColdStart(Thread $thread): void
    {
        $manifest = PreviewManifest::fromArray(Repository::where('slug', $thread->repo)->first()?->preview_manifest);

        if ($manifest->coldStart !== '') {
            $this->sandboxes->exec($thread, ['bash', '-lc', $manifest->coldStart]);
        }
    }

    private function stopLocked(Thread $thread): void
    {
        $thread->refresh();

        if ($thread->isSandboxBusy() || ! in_array($thread->sandbox_state, [ThreadSandboxState::Running, ThreadSandboxState::Paused], true)) {
            return;
        }

        if ($thread->sandbox_state === ThreadSandboxState::Paused) {
            $this->sandboxes->resume($thread);
            $this->record($thread, ThreadSandboxState::Running);
        }

        $timeout = (int) config('yak.threads.stop_timeout_seconds');
        $this->streams->command("threads:{$thread->id}", ['type' => 'end']);
        $this->waitForRunnerExit($thread, $timeout);

        try {
            $this->sessions->syncFromSandbox($thread);
        } catch (Throwable $exception) {
            Log::warning('Session sync failed while stopping a thread sandbox', ['thread' => $thread->id, 'error' => $exception->getMessage()]);
        }

        $this->sandboxes->stop($thread, $timeout);
        $this->record($thread, ThreadSandboxState::Stopped);
        $thread->update(['runner_state' => RunnerState::None]);
    }

    private function waitForRunnerExit(Thread $thread, int $timeoutSeconds): void
    {
        for ($waited = 0; $waited < $timeoutSeconds; $waited++) {
            if ($thread->fresh()?->runner_state === RunnerState::None) {
                return;
            }

            Sleep::for(1)->second();
        }
    }

    private function exists(Thread $thread): bool
    {
        return ! in_array($thread->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroyed], true);
    }

    private function record(Thread $thread, ThreadSandboxState $state): void
    {
        $values = ['sandbox_state' => $state->value, 'sandbox_state_changed_at' => now()];

        if ($thread->sandbox_container !== null) {
            Thread::withoutGlobalScopes()->where('sandbox_container', $thread->sandbox_container)->update($values);
        }

        $thread->forceFill($values)->save();
        $thread->refresh();
    }

    private function lockName(Thread $thread): string
    {
        return "thread-sandbox:{$thread->id}";
    }
}
