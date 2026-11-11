<?php

namespace App\Services\Threads;

use App\DataTransferObjects\SandboxWake;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Datasets\DatasetClones;
use App\Services\Stacks\ResearchChildRunner;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
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
        private WipPreserver $wip,
        private SandboxRestorer $restorer,
        private AuthProxyPlaceholders $placeholders,
        private DatasetClones $datasetClones,
        private ThreadPreviewDevServer $previewServer,
    ) {}

    public function touch(Thread $thread): void
    {
        if (Cache::add("thread-sandbox:touch:{$thread->id}", true, self::TOUCH_THROTTLE_SECONDS)) {
            $thread->forceFill(['last_activity_at' => now()])->save();
        }
    }

    public function pauseIfIdle(Thread $thread): void
    {
        if ($thread->sharesParentSandbox()) {
            return;
        }

        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $thread->refresh();

            if ($thread->sandbox_state !== ThreadSandboxState::Running || $thread->isSandboxBusy() || app(ThreadPreviewActivity::class)->isActive($thread)) {
                return;
            }

            $this->sandboxes->pause($thread);
            $this->record($thread, ThreadSandboxState::Paused);
        } finally {
            $lock->release();
        }
    }

    public function stop(Thread $thread, bool $preserveWork = false): void
    {
        if ($thread->sharesParentSandbox()) {
            $this->releaseResearchChild($thread);

            return;
        }

        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->stopLocked($thread, $preserveWork);
        } finally {
            $lock->release();
        }
    }

    public function destroy(Thread $thread): void
    {
        if ($thread->sharesParentSandbox()) {
            $this->releaseResearchChild($thread);

            return;
        }

        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $thread->refresh();

            if ($thread->isSandboxBusy() || ! $this->exists($thread)) {
                return;
            }

            $this->wakeForPreserve($thread);

            try {
                $this->sessions->syncFromSandbox($thread);
            } catch (Throwable $exception) {
                Log::warning('Session sync failed while destroying a thread sandbox', ['thread' => $thread->id, 'error' => $exception->getMessage()]);
            }

            // Never destroy a sandbox whose work could not be pushed; the sweep retries.
            if (! $this->preserveWork($thread)) {
                return;
            }

            $this->sandboxes->destroy($thread);
            $this->datasetClones->destroyFor($thread);
            $this->record($thread, ThreadSandboxState::Destroyed);
            $this->revokeSharedPlaceholders($thread);
        } finally {
            $lock->release();
        }
    }

    public function ensureAwake(Thread $thread): SandboxWake
    {
        if ($thread->sharesParentSandbox()) {
            return $this->ensureAwake(Thread::query()->withoutGlobalScopes()->findOrFail($thread->parent_thread_id));
        }

        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return SandboxWake::restoring();
        }

        try {
            $thread->refresh();

            if ($thread->blocked_reason === ThreadBlockReason::RestoreFailed) {
                return $this->restoreAwake($thread);
            }

            return match ($thread->sandbox_state) {
                ThreadSandboxState::Running => SandboxWake::ready(),
                ThreadSandboxState::Starting => SandboxWake::restoring(),
                ThreadSandboxState::Paused => $this->resume($thread),
                ThreadSandboxState::Stopped => $this->startStopped($thread),
                ThreadSandboxState::None, ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed => $this->restoreAwake($thread),
            };
        } finally {
            $lock->release();
        }
    }

    /**
     * A research child owns no container: its runner is killed, its worktree
     * and config directory go, its placeholder is revoked and it lets go of
     * the parent's container. The container itself is never touched.
     */
    private function releaseResearchChild(Thread $child): void
    {
        $parent = Thread::query()->withoutGlobalScopes()->findOrFail($child->parent_thread_id);

        $this->streams->command("threads:{$child->id}", ['type' => 'kill']);

        if (in_array($parent->sandbox_state, [ThreadSandboxState::Running, ThreadSandboxState::Paused], true)) {
            try {
                $this->sandboxes->exec($parent, ['bash', '-c', 'git worktree remove --force "$1"; rm -rf "$2"', 'bash', ResearchChildRunner::worktreePath($child), $child->claude_config_dir]);
            } catch (Throwable $exception) {
                Log::warning('Dropping a research worktree failed', ['thread' => $child->id, 'error' => $exception->getMessage()]);
            }
        }

        $this->placeholders->revoke($child);
        $child->forceFill([
            'sandbox_container' => null,
            'sandbox_state' => ThreadSandboxState::Destroyed,
            'sandbox_state_changed_at' => now(),
            'runner_state' => RunnerState::None,
        ])->save();
    }

    private function resume(Thread $thread): SandboxWake
    {
        $this->sandboxes->resume($thread);
        $this->record($thread, ThreadSandboxState::Running);

        return SandboxWake::ready();
    }

    /** Rebuilds the sandbox, or resumes a restore that failed part way. */
    public function restore(Thread $thread): bool
    {
        $lock = Cache::lock($this->lockName($thread), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            $thread->refresh();

            return $this->restoreAwake($thread)->ready;
        } finally {
            $lock->release();
        }
    }

    private function restoreAwake(Thread $thread): SandboxWake
    {
        $needsSlot = in_array($thread->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed, ThreadSandboxState::Stopped], true);

        if ($needsSlot && ! $this->makeRoom($thread)) {
            return SandboxWake::waitingForSlot();
        }

        $this->wakeForPreserve($thread);

        if (! $this->restorer->restore($thread)) {
            return new SandboxWake(false, false, false);
        }

        $thread->update(['runner_state' => RunnerState::None]);
        $this->runColdStart($thread);
        $this->clearWaiting($thread);

        return SandboxWake::ready();
    }

    private function startStopped(Thread $thread): SandboxWake
    {
        if (! $this->makeRoom($thread)) {
            return SandboxWake::waitingForSlot();
        }

        $this->sandboxes->start($thread);
        $this->record($thread, ThreadSandboxState::Running);
        $thread->update(['runner_state' => RunnerState::None]);
        $this->runColdStart($thread);
        $this->clearWaiting($thread);

        return SandboxWake::ready();
    }

    /** Evicts idle sandboxes until the thread fits; false when it has to wait for a slot. */
    private function makeRoom(Thread $thread): bool
    {
        while (($reason = $this->capacity->needsEviction($thread)) !== null) {
            $candidate = $this->capacity->evictionCandidate($thread, $reason);

            if ($candidate === null) {
                return $this->waitForSlot($thread);
            }

            $this->stop($candidate, true);
            $candidate->refresh();

            if ($candidate->sandbox_state !== ThreadSandboxState::Stopped) {
                // The candidate could not be stopped; do not loop on it.
                return $this->waitForSlot($thread);
            }
        }

        return true;
    }

    private function waitForSlot(Thread $thread): bool
    {
        $thread->update(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => $thread->blocked_at ?? now()]);

        return false;
    }

    private function clearWaiting(Thread $thread): void
    {
        if ($thread->blocked_reason === ThreadBlockReason::WaitingForSandbox) {
            $thread->update(['blocked_reason' => null, 'blocked_at' => null]);
        }
    }

    private function runColdStart(Thread $thread): void
    {
        $this->previewServer->ensureStarted($thread);
    }

    private function stopLocked(Thread $thread, bool $preserveWork): void
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

        if ($preserveWork && ! $this->preserveWork($thread)) {
            return;
        }

        $this->sandboxes->stop($thread, $timeout);
        $this->record($thread, ThreadSandboxState::Stopped);
        $thread->update(['runner_state' => RunnerState::None]);
    }

    private function wakeForPreserve(Thread $thread): void
    {
        if ($thread->sandbox_state === ThreadSandboxState::Paused) {
            $this->sandboxes->resume($thread);
            $this->record($thread, ThreadSandboxState::Running);
        } elseif ($thread->sandbox_state === ThreadSandboxState::Stopped) {
            $this->sandboxes->start($thread);
            $this->record($thread, ThreadSandboxState::Running);
        }
    }

    private function preserveWork(Thread $thread): bool
    {
        try {
            $this->wip->preserveAll($thread);

            return true;
        } catch (Throwable $exception) {
            Log::error('Preserving work failed for a thread sandbox', ['thread' => $thread->id, 'error' => $exception->getMessage()]);

            return false;
        }
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

    private function revokeSharedPlaceholders(Thread $thread): void
    {
        $sharing = $thread->sandbox_container === null
            ? collect([$thread])
            : Thread::withoutGlobalScopes()->where('sandbox_container', $thread->sandbox_container)->get();

        foreach ($sharing as $sharer) {
            $this->placeholders->revoke($sharer);
        }
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
