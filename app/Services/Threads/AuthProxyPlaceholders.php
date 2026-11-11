<?php

namespace App\Services\Threads;

use App\Models\ClaudeAccount;
use App\Models\Thread;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Placeholders for the auth proxy. A sandbox only ever holds a placeholder
 * (`yakph-` plus 32 random bytes). It is bound to one subject (a thread or a
 * run id), one account and one container ip. The proxy redeems it over the
 * credential socket. Only the sha256 of the placeholder is stored, never the
 * placeholder or the account secret.
 */
class AuthProxyPlaceholders
{
    public const PREFIX = 'yakph-';

    /** Longer than any runner lives. */
    private const TTL_SECONDS = 172_800;

    public function issue(Thread|string $subject, ClaudeAccount $account, string $containerIp): string
    {
        $subjectKey = $this->subjectKey($subject);
        $this->revoke($subject);

        $placeholder = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $placeholder);

        $this->store()->put('auth-proxy-placeholder:' . $hash, [
            'account_id' => $account->id,
            'container_ip' => $containerIp,
            'subject' => $subjectKey,
        ], self::TTL_SECONDS);
        $this->store()->put('auth-proxy-subject:' . $subjectKey, $hash, self::TTL_SECONDS);

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

        if (str_starts_with($subject, 'thread:') && Thread::query()->find((int) substr($subject, 7))?->isOpen() !== true) {
            return null;
        }

        return ClaudeAccount::query()->find((int) $binding['account_id']);
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
