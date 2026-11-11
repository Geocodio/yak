<?php

namespace App\Services\Threads\Preview;

use App\DataTransferObjects\PreviewWakeOutcome;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\DeploymentContainerManager;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Sleep;

/**
 * Brings a thread's sandbox and dev server up for a preview request and holds
 * the request for at most the hold limit. Reads Yak's own sandbox state, never
 * the hypervisor. A closed thread, a local machine thread or a destroyed
 * sandbox is never woken or restored by a view.
 */
class ThreadPreviewWaker
{
    private const POLL_MICROSECONDS = 250_000;

    public function __construct(
        private ThreadSandbox $sandbox,
        private ThreadPreviewDevServer $devServer,
        private DeploymentContainerManager $containers,
    ) {}

    public function wake(Thread $thread, int $holdSeconds): PreviewWakeOutcome
    {
        $thread->refresh();

        if (! $thread->isOpen()) {
            return PreviewWakeOutcome::closed();
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return PreviewWakeOutcome::localMachine();
        }

        if (in_array($thread->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed], true)) {
            return PreviewWakeOutcome::destroyed();
        }

        if ($thread->sandbox_state === ThreadSandboxState::Running && ($port = $this->devServer->readyPort($thread)) !== null) {
            return $this->ready($thread, $port, []);
        }

        $deadline = now()->addSeconds($holdSeconds);
        $steps = [];

        if ($thread->sandbox_state !== ThreadSandboxState::Running) {
            $steps[] = 'Waking the sandbox';
            $wake = $this->sandbox->ensureAwake($thread);

            if ($wake->waitingForSlot) {
                return PreviewWakeOutcome::waitingForSlot($steps);
            }

            if (! $wake->ready && ! $wake->restoring) {
                return PreviewWakeOutcome::failing('The sandbox could not be woken.', $steps);
            }

            while ($thread->sandbox_state !== ThreadSandboxState::Running && now()->lessThan($deadline)) {
                Sleep::usleep(self::POLL_MICROSECONDS);
                $thread->refresh();
            }

            if ($thread->sandbox_state !== ThreadSandboxState::Running) {
                return PreviewWakeOutcome::waking($steps);
            }
        }

        $steps[] = 'Starting the dev server';
        $state = $this->devServer->ensureStarted($thread);

        while ($state === 'starting' && now()->lessThan($deadline)) {
            Sleep::usleep(self::POLL_MICROSECONDS);
            $state = $this->devServer->state($thread);
        }

        return match ($state) {
            'ready' => ($port = $this->devServer->readyPort($thread)) === null ? PreviewWakeOutcome::waking($steps) : $this->ready($thread, $port, $steps),
            'no_manifest' => PreviewWakeOutcome::noManifest(),
            'invalid_manifest', 'failing' => PreviewWakeOutcome::failing($this->devServer->error($thread) ?? 'The dev server did not start.', $steps),
            default => PreviewWakeOutcome::waking($steps),
        };
    }

    /** @param list<string> $steps */
    private function ready(Thread $thread, int $port, array $steps): PreviewWakeOutcome
    {
        return PreviewWakeOutcome::ready($this->containers->resolveContainerIp((string) $thread->sandbox_container), $port, $steps);
    }
}
