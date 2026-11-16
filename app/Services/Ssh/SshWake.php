<?php

namespace App\Services\Ssh;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;

/**
 * Wakes the sandbox behind a wake listener. It only resumes or starts an existing sandbox; a destroyed one is never
 * restored from a connection, because that rebuild belongs to the person using the thread page.
 */
class SshWake
{
    public function __construct(
        private ThreadSandbox $sandbox,
        private SandboxAuthorizedKeys $authorizedKeys,
    ) {}

    public function handle(Thread $thread): SshWakeResult
    {
        if (! $thread->isOpen()) {
            return new SshWakeResult('closed', message: 'This thread is closed.');
        }

        if (in_array($thread->sandbox_state, [ThreadSandboxState::Destroyed, ThreadSandboxState::Destroying], true)) {
            return new SshWakeResult('destroyed', message: 'This sandbox was destroyed. Restore it from the thread page.');
        }

        if ($thread->sandbox_state === ThreadSandboxState::None
            || $thread->owner_side !== ThreadOwnerSide::Yak
            || $thread->blocked_reason === ThreadBlockReason::RestoreFailed) {
            return new SshWakeResult('no_sandbox');
        }

        $wake = $this->sandbox->ensureAwake($thread);

        if (! $wake->ready) {
            return new SshWakeResult('waiting', message: $wake->waitingForSlot ? 'Waiting for a free sandbox.' : 'The sandbox is starting.');
        }

        $this->authorizedKeys->sync($thread);
        $this->sandbox->touch($thread);

        return new SshWakeResult('ready', $thread->sandbox_container);
    }
}
