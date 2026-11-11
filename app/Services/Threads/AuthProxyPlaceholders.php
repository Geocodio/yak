<?php

namespace App\Services\Threads;

use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Placeholders for the auth proxy. A sandbox only ever holds a placeholder
 * (`yakph-` plus 32 random bytes). It is bound to one subject (a thread or a
 * run id), one account and one container ip. A thread placeholder is also
 * bound to the sandbox container it was issued for, and only the newest
 * placeholder of a subject is live. It stops resolving when the container changes, the thread closes or the sandbox is
 * destroyed. The proxy redeems it over the
 * credential socket. Only the sha256 of the placeholder is stored, never the
 * placeholder or the account secret.
 */
class AuthProxyPlaceholders
{
    public const PREFIX = 'yakph-';

    /** Backstop for thread placeholders; revocation and the one-live-placeholder rule are the real controls. */
    private const THREAD_TTL_SECONDS = 604_800;

    /** Extra minutes beyond one turn's wall clock for placeholders of one-shot runs. */
    private const RUN_GRACE_MINUTES = 10;

    public function issue(Thread|string $subject, ClaudeAccount $account, string $containerIp, ?string $containerName = null): string
    {
        $subjectKey = $this->subjectKey($subject);
        $this->revoke($subject);

        // ponytail: one-shot runs have no record to check yet, so they live for one turn plus a grace period.
        $ttl = $subject instanceof Thread
            ? self::THREAD_TTL_SECONDS
            : ((int) config('yak.threads.turn_wall_clock_minutes') + self::RUN_GRACE_MINUTES) * 60;
        $containerName ??= $subject instanceof Thread ? $subject->sandbox_container : null;

        $placeholder = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $placeholder);

        $this->store()->put('auth-proxy-placeholder:' . $hash, [
            'account_id' => $account->id,
            'container_ip' => $containerIp,
            'subject' => $subjectKey,
            'container' => $containerName,
        ], $ttl);
        $this->store()->put('auth-proxy-subject:' . $subjectKey, $hash, $ttl);

        return $placeholder;
    }

    public function revoke(Thread|string $subject): void
    {
        $hash = $this->store()->pull('auth-proxy-subject:' . $this->subjectKey($subject));

        if (is_string($hash)) {
            $this->store()->forget('auth-proxy-placeholder:' . $hash);
        }
    }

    /**
     * The account behind a placeholder, or null when the placeholder is wrong,
     * unknown, revoked, used from another container, or its account or thread
     * is gone. An invalid or expired account still resolves so the proxy can
     * relay the upstream 401.
     */
    public function resolve(string $placeholder, string $containerIp): ?ClaudeAccount
    {
        if (! str_starts_with($placeholder, self::PREFIX)) {
            return null;
        }

        $binding = $this->store()->get('auth-proxy-placeholder:' . hash('sha256', $placeholder));

        if (! is_array($binding) || ! hash_equals((string) $binding['container_ip'], $containerIp)) {
            return null;
        }

        $subject = (string) $binding['subject'];

        if (str_starts_with($subject, 'thread:') && ! $this->threadMatches((int) substr($subject, 7), $binding)) {
            return null;
        }

        return ClaudeAccount::query()->find((int) $binding['account_id']);
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function threadMatches(int $threadId, array $binding): bool
    {
        $thread = Thread::query()->find($threadId);

        return $thread !== null
            && $thread->isOpen()
            && ! in_array($thread->sandbox_state, [ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed], true)
            && $thread->sandbox_container === ($binding['container'] ?? null);
    }

    private function subjectKey(Thread|string $subject): string
    {
        return $subject instanceof Thread ? 'thread:' . $subject->id : $subject;
    }

    private function store(): Repository
    {
        return Cache::store((string) config('yak.threads.placeholder_store'));
    }
}
