<?php

namespace App\Services\Threads;

use App\DataTransferObjects\PreviewManifest;
use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Exceptions\SandboxCapacityReached;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\IncusSandboxManager;

/**
 * Moves a thread's sandbox between none, running, paused and stopped, and
 * records each move on the thread row.
 */
class ThreadSandbox
{
    public function __construct(private readonly IncusSandboxManager $sandboxes) {}

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
        }

        // Neither path leaves a runner behind; the turn starts one with resume.
        $thread->forceFill(['runner_state' => RunnerState::None]);
        $this->record($thread, ThreadSandboxState::Running);

        return $container;
    }

    /**
     * Pauses the sandbox between turns. A thread with a turn in flight stays awake.
     */
    public function pause(Thread $thread): void
    {
        if ($thread->sandbox_state !== ThreadSandboxState::Running || $thread->runningTurn() !== null) {
            return;
        }

        $this->sandboxes->pause((string) $thread->sandbox_container);
        $this->record($thread, ThreadSandboxState::Paused);
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

        if ($awake >= (int) config('yak.threads.awake_cap')) {
            throw new SandboxCapacityReached("Thread {$thread->id} cannot wake: {$awake} sandboxes are already awake.");
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
