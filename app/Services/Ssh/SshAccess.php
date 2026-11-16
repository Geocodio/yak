<?php

namespace App\Services\Ssh;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Collection;

/**
 * Who may open a shell in which sandbox: whoever may drive the thread, while it is open, lives on the Yak side and
 * still has a sandbox. Everyone else gets no key line, so the jump host refuses them before any sandbox is reached.
 */
class SshAccess
{
    public const MAX_SANDBOXES_PER_KEY = 200;

    public function __construct(private ThreadAccess $access) {}

    public function canOpenShell(?User $user, Thread $thread): bool
    {
        return $this->denial($user, $thread) === null;
    }

    /**
     * @return 'not_found'|'forbidden'|'thread_closed'|'thread_owned_by_device'|'sandbox_destroyed'|'no_sandbox'|null
     */
    public function denial(?User $user, Thread $thread): ?string
    {
        return match (true) {
            ! $this->access->canSee($user, $thread) => 'not_found',
            ! $this->access->canDrive($user, $thread) => 'forbidden',
            $thread->status !== ThreadStatus::Open => 'thread_closed',
            $thread->owner_side !== ThreadOwnerSide::Yak => 'thread_owned_by_device',
            in_array($thread->sandbox_state, [ThreadSandboxState::Destroyed, ThreadSandboxState::Destroying], true) => 'sandbox_destroyed',
            $thread->sandbox_state === ThreadSandboxState::None => 'no_sandbox',
            default => null,
        };
    }

    /**
     * @return Collection<int, Thread>
     */
    public function drivableThreads(User $user): Collection
    {
        return $this->access->scopeVisibleTo(Thread::withoutGlobalScopes()->with('root'), $user)
            ->where('status', ThreadStatus::Open->value)
            ->where('owner_side', ThreadOwnerSide::Yak->value)
            ->whereNotIn('sandbox_state', [ThreadSandboxState::None->value, ThreadSandboxState::Destroying->value, ThreadSandboxState::Destroyed->value])
            ->orderBy('id')
            ->get()
            ->filter(fn (Thread $thread): bool => $this->access->canDrive($user, $thread))
            ->values();
    }

    /**
     * People with at least one key who may drive the thread.
     *
     * @return Collection<int, User>
     */
    public function drivers(Thread $thread): Collection
    {
        $candidates = User::query()->whereHas('sshKeys');

        if ($thread->isPrivate()) {
            $candidates->whereKey($thread->effectiveOwnerUserId() ?? 0);
        }

        return $candidates->orderBy('id')->get()
            ->filter(fn (User $user): bool => $this->access->canDrive($user, $thread))
            ->values();
    }

    /**
     * One line for the presented key. sshd honours only the first matching line, so every reachable sandbox is a
     * permitopen option on that one line.
     *
     * @return list<string>
     */
    public function authorizedKeyLines(string $fingerprint): array
    {
        $key = app(SshKeyDirectory::class)->keyForFingerprint($fingerprint);
        $user = $key?->user;

        if ($key === null || $user === null) {
            return [];
        }

        $threads = $this->drivableThreads($user)->take(self::MAX_SANDBOXES_PER_KEY);

        if ($threads->isEmpty()) {
            return [];
        }

        $options = $threads->map(fn (Thread $thread): string => 'permitopen="' . SandboxSshAddress::for($thread) . ':22"')->implode(',');

        return ["restrict,port-forwarding,{$options} {$key->public_key}"];
    }
}
