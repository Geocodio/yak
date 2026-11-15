<?php

namespace App\Services\Threads\Files;

use App\DataTransferObjects\Files\SandboxUnavailable;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadRecovery;
use App\Services\Threads\ThreadSandbox;

/** The one rule for whether a sandbox can serve files to a person now, and if not, why. */
class SandboxFileAvailability
{
    public function __construct(private readonly ThreadSandbox $sandbox, private readonly ThreadAccess $access) {}

    public function check(Thread $thread, User $user): ?SandboxUnavailable
    {
        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return new SandboxUnavailable(SandboxUnavailable::ON_DEVICE);
        }

        if ($thread->sandbox_state === ThreadSandboxState::Running) {
            return null;
        }

        $canDrive = $this->access->canDrive($user, $thread);

        if ($thread->isOpen() && ThreadRecovery::isRestoreQueued($thread)) {
            return new SandboxUnavailable(
                SandboxUnavailable::WAKING,
                waitingForSlot: $thread->blocked_reason === ThreadBlockReason::WaitingForSandbox,
                restoring: true,
            );
        }

        switch ($thread->sandbox_state) {
            case ThreadSandboxState::None:
            case ThreadSandboxState::Destroying:
            case ThreadSandboxState::Destroyed:
                if (! $thread->isOpen()) {
                    return new SandboxUnavailable(SandboxUnavailable::CLOSED_DESTROYED);
                }

                $canRestore = $canDrive && (
                    $thread->sandbox_state !== ThreadSandboxState::None
                    || $thread->blocked_reason === ThreadBlockReason::RestoreFailed
                );

                return new SandboxUnavailable(SandboxUnavailable::DESTROYED, canRestore: $canRestore);
            case ThreadSandboxState::Starting:
                return new SandboxUnavailable(SandboxUnavailable::WAKING, restoring: true);
            default:
                if (! $canDrive) {
                    return new SandboxUnavailable(SandboxUnavailable::ASLEEP);
                }

                $wake = $this->sandbox->ensureAwake($thread);

                return $wake->ready
                    ? null
                    : new SandboxUnavailable(SandboxUnavailable::WAKING, waitingForSlot: $wake->waitingForSlot, restoring: $wake->restoring);
        }
    }
}
