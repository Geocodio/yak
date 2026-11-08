<?php

namespace App\Services\Threads;

use App\DataTransferObjects\PreviewManifest;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Exceptions\SandboxCapacityReached;
use App\Exceptions\ThreadBusy;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\IncusSandboxManager;
use App\Services\SessionStore\SessionSync;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Moves a thread's sandbox between none, running, paused and stopped, and
 * records each move on the thread row.
 */
class ThreadSandbox
{
    public function __construct(
        private readonly IncusSandboxManager $sandboxes,
        private readonly ThreadCommands $commands,
        private readonly SessionSync $sessionSync,
    ) {}

    /**
     * Runs the before-turn work (wake, prepare the tree, start the turn) as the
     * only holder of the thread's turn lock. The same lock guards pausing and
     * stopping, so a sandbox is never frozen or stopped under a turn that is
     * about to start. A thread that already runs a turn is never started twice.
     *
     * @template T
     *
     * @param  Closure(): T  $startTurn
     * @return T
     *
     * @throws ThreadBusy
     */
    public function startingTurn(Thread $thread, Closure $startTurn): mixed
    {
        $lock = $this->turnLock($thread);

        if (! $lock->get()) {
            throw new ThreadBusy("Thread {$thread->id} is starting a turn or stopping its sandbox.");
        }

        try {
            $current = $thread->fresh() ?? $thread;

            if ($current->runningTurn() !== null || $current->lock_holder !== ThreadLockHolder::None) {
                throw new ThreadBusy("Thread {$thread->id} already runs a turn.");
            }

            return $startTurn();
        } finally {
            $lock->release();
        }
    }

    private function turnLock(Thread $thread): Lock
    {
        return Cache::lock('thread-turn:' . $thread->id, 1800);
    }

    /**
     * Returns the thread's container, awake and ready for a turn.
     *
     * @throws SandboxCapacityReached when a sandbox would have to wake past the awake cap
     */
    public function ensureAwake(Thread $thread, Repository $repository): string
    {
        $container = (string) $thread->sandbox_container;
        $state = $thread->sandbox_state;

        // The host may have stopped a container behind our back (a reboot).
        if (in_array($state, [ThreadSandboxState::Running, ThreadSandboxState::Paused], true) && $this->sandboxes->state($container) === 'stopped') {
            $state = ThreadSandboxState::Stopped;
        }

        if ($state === ThreadSandboxState::Running) {
            return $container;
        }

        if ($state === ThreadSandboxState::Paused) {
            $this->sandboxes->resume($container);
            $this->record($thread, ThreadSandboxState::Running);

            return $container;
        }

        $this->assertRoomToWake($thread);

        if ($state === ThreadSandboxState::Stopped) {
            $this->record($thread, ThreadSandboxState::Starting);
            $this->sandboxes->start($container);
            $this->runColdStart($container, $repository);
        } else {
            $this->record($thread, ThreadSandboxState::Starting);
            $container = $this->sandboxes->createForThread($thread, $repository);
            $thread->forceFill(['sandbox_container' => $container]);
            $this->checkoutThreadBranch($thread, $container);
            $this->sessionSync->restoreIntoSandbox($thread, $container);
        }

        // Neither path leaves a runner behind; the turn starts one with resume.
        $thread->forceFill(['runner_state' => RunnerState::None]);

        if ($thread->blocked_reason === ThreadBlockReason::WaitingForSandbox) {
            $thread->forceFill(['blocked_reason' => null]);
        }

        $this->record($thread, ThreadSandboxState::Running);

        return $container;
    }

    /**
     * Pauses the sandbox between turns, after uploading the session files when
     * asked. A thread with a turn in flight, or one that is being started or
     * finished, stays awake.
     */
    public function pause(Thread $thread, bool $syncSessions = false): void
    {
        $lock = $this->turnLock($thread);

        // A turn is being started or finished: the sandbox stays awake and that work pauses it when it ends.
        if (! $lock->get()) {
            return;
        }

        try {
            $thread->refresh();

            if ($thread->sandbox_state !== ThreadSandboxState::Running || $thread->runningTurn() !== null) {
                return;
            }

            if ($syncSessions) {
                $this->syncSessions($thread, (string) $thread->sandbox_container);
            }

            $this->sandboxes->pause((string) $thread->sandbox_container);
            $this->record($thread, ThreadSandboxState::Paused);
        } finally {
            $lock->release();
        }
    }

    /**
     * Holds the thread's turn lock while the after-turn work uses the sandbox, so no pause,
     * stop or new turn touches the working tree under it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function holdingSandbox(Thread $thread, Closure $work): mixed
    {
        return $this->turnLock($thread)->block(300, $work);
    }

    /**
     * Ends the runner, waits for it to exit, then stops the container, forcing
     * the stop when the runner outlives stop_timeout_seconds. A thread with a
     * turn in flight is left alone.
     */
    public function stop(Thread $thread): void
    {
        $lock = $this->turnLock($thread);

        // A turn is being started on this thread; try again on the next sweep.
        if (! $lock->get()) {
            return;
        }

        try {
            $thread->refresh();

            if (in_array($thread->sandbox_state, [ThreadSandboxState::Running, ThreadSandboxState::Paused], true) && $thread->runningTurn() === null) {
                $this->stopLocked($thread);
            }
        } finally {
            $lock->release();
        }
    }

    private function stopLocked(Thread $thread): void
    {
        $container = (string) $thread->sandbox_container;
        $timeout = (int) config('yak.threads.stop_timeout_seconds');

        // A frozen container cannot deliver the end command.
        if ($thread->sandbox_state === ThreadSandboxState::Paused) {
            $this->sandboxes->resume($container);
            $this->record($thread, ThreadSandboxState::Running);
        }

        if ($thread->runner_state !== RunnerState::None) {
            $this->commands->end($thread);

            for ($waited = 0; $waited < $timeout && $thread->fresh()?->runner_state !== RunnerState::None; $waited++) {
                Sleep::for(1)->second();
            }
        }

        $this->syncSessions($thread, $container);
        $this->sandboxes->stop($container, $timeout);
        $thread->refresh()->forceFill(['runner_state' => RunnerState::None]);
        $this->record($thread, ThreadSandboxState::Stopped);
    }

    public function destroy(Thread $thread): void
    {
        $container = $thread->sandbox_container;

        if ($container === null || in_array($thread->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroyed], true)) {
            return;
        }

        if ($thread->sandbox_state === ThreadSandboxState::Paused) {
            $this->sandboxes->resume($container);
        }

        $this->syncSessions($thread, $container);
        $this->record($thread, ThreadSandboxState::Destroying);
        $this->commands->kill($thread);
        $this->sandboxes->destroy($container);
        $thread->forceFill(['runner_state' => RunnerState::None]);
        $this->record($thread, ThreadSandboxState::Destroyed);
    }

    /**
     * Stops the paused sandbox with the oldest activity once it has been
     * paused for the eviction grace period.
     */
    public function evictOne(): bool
    {
        $thread = Thread::query()
            ->where('sandbox_state', ThreadSandboxState::Paused)
            ->where('sandbox_state_changed_at', '<=', now()->subMinutes((int) config('yak.threads.eviction_grace_minutes')))
            ->orderBy('last_activity_at')
            ->orderBy('id')
            ->get()
            ->first(fn (Thread $candidate): bool => $candidate->runningTurn() === null);

        if ($thread === null) {
            return false;
        }

        $this->stop($thread);

        return true;
    }

    /**
     * ponytail: count then wake is not atomic, so two simultaneous wakes can
     * overshoot the cap by one. Take a lock around the count if that matters.
     */
    private function assertRoomToWake(Thread $thread): void
    {
        $awake = Thread::query()
            ->whereKeyNot($thread->id)
            ->whereIn('sandbox_state', [ThreadSandboxState::Starting, ThreadSandboxState::Running, ThreadSandboxState::Paused])
            ->count();

        if ($awake >= (int) config('yak.threads.awake_cap') && ! $this->evictOne()) {
            $thread->forceFill(['blocked_reason' => ThreadBlockReason::WaitingForSandbox])->save();

            throw new SandboxCapacityReached("Thread {$thread->id} cannot wake: {$awake} sandboxes are already awake.");
        }
    }

    /**
     * Uploads the session files before the container goes away. A failed
     * upload is logged and does not block the stop or destroy: a stopped
     * container keeps its disk, and a thread must always be able to close.
     */
    public function syncSessions(Thread $thread, string $container): void
    {
        try {
            $this->sessionSync->syncFromSandbox($thread, $container);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Session sync failed', ['thread_id' => $thread->id, 'error' => $exception->getMessage()]);
        }
    }

    private function runColdStart(string $container, Repository $repository): void
    {
        $manifest = PreviewManifest::fromArray($repository->preview_manifest);

        if ($manifest->coldStart === '') {
            return;
        }

        $this->sandboxes->run(
            $container,
            'cd ' . IncusSandboxManager::workspacePath() . ' && ' . $manifest->coldStart,
            timeout: $manifest->coldStartTimeoutSeconds,
        );
    }

    private function checkoutThreadBranch(Thread $thread, string $container): void
    {
        $branch = $thread->tasks()->whereNotNull('branch_name')->latest('id')->value('branch_name');

        if ($branch === null) {
            return;
        }

        $workspace = IncusSandboxManager::workspacePath();
        $name = escapeshellarg($branch);

        $this->sandboxes->configureGitIdentity($container);
        $this->sandboxes->injectGitCredentials($container);
        $this->sandboxes->run($container, "cd {$workspace} && git fetch origin {$name}", timeout: 60);
        $this->sandboxes->run($container, "cd {$workspace} && git checkout {$name}", timeout: 30);
    }

    private function record(Thread $thread, ThreadSandboxState $state): void
    {
        $thread->forceFill(['sandbox_state' => $state, 'sandbox_state_changed_at' => now()])->save();
    }
}
