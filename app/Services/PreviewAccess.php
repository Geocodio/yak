<?php

namespace App\Services;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadVisibility;
use App\Models\BranchDeployment;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-preview, host-only access for branch preview hostnames.
 *
 * The dashboard session cookie is host-only, so previews never receive it.
 * Instead the dashboard mints a short-lived single-use ticket for one preview
 * host, the preview host exchanges it for a signed cookie, and that cookie is
 * what the wake endpoint validates on later requests. Tickets and cookies are
 * HMAC-signed with the app key and bound to the preview host.
 */
class PreviewAccess
{
    public const COOKIE_NAME = '__Host-yak_preview';

    public const TICKET_TTL_SECONDS = 60;

    public const COOKIE_TTL_SECONDS = 8 * 3600;

    public function mintTicket(string $host, User $user): string
    {
        return $this->seal('ticket', [Str::random(24), strtolower($host), $user->id, now()->getTimestamp() + self::TICKET_TTL_SECONDS]);
    }

    /**
     * Returns the user id the ticket was minted for, or null when the ticket
     * is tampered, expired, for another host, or already used.
     */
    public function redeemTicket(string $host, string $ticket): ?int
    {
        $parts = $this->open('ticket', $ticket);
        if ($parts === null || count($parts) !== 4) {
            return null;
        }

        [$nonce, $ticketHost, $userId, $expiresAt] = $parts;
        if ($ticketHost !== strtolower($host) || (int) $expiresAt < now()->getTimestamp()) {
            return null;
        }

        if (! $this->mayEnter($ticketHost, (int) $userId)) {
            return null;
        }

        // Cache::add is atomic: only the first redemption of a nonce wins.
        if (! Cache::add('preview-ticket:' . $nonce, 1, self::TICKET_TTL_SECONDS)) {
            return null;
        }

        return (int) $userId;
    }

    public function verifyCookie(string $host, string $value): bool
    {
        $userId = $this->cookieUserId($host, $value);

        return $userId !== null && $this->mayEnter($host, $userId);
    }

    /**
     * True for a genuine preview cookie whose user may not see the preview's
     * thread: the caller answers it exactly like an unknown host.
     */
    public function cookieIsHidden(string $host, string $value): bool
    {
        $userId = $this->cookieUserId($host, $value);

        return $userId !== null && ! $this->mayEnter($host, $userId);
    }

    /**
     * Which kind of target a host names: a branch deployment, a thread
     * preview host, or neither.
     */
    public function targetKind(string $host): ?string
    {
        if ($this->threadFor($host) !== null) {
            return 'thread';
        }

        return BranchDeployment::withoutGlobalScopes()->where('hostname', strtolower($host))->exists() ? 'deployment' : null;
    }

    public function threadFor(string $host): ?Thread
    {
        return app(ThreadPreviewHosts::class)->threadForHost($host);
    }

    /**
     * Whether the user may open the preview of this host: the user exists and
     * may see the thread the preview belongs to. A thread host also needs an
     * open thread that is not owned by a local machine. A host with no
     * deployment, or a deployment outside any thread, has nothing to hide.
     */
    public function mayEnter(string $host, int $userId): bool
    {
        $user = User::find($userId);
        if ($user === null) {
            return false;
        }

        $thread = $this->threadFor($host);
        if ($thread !== null) {
            return $thread->isOpen()
                && $thread->owner_side !== ThreadOwnerSide::Device
                && app(ThreadAccess::class)->canSee($user, $thread);
        }

        return $this->mayEnterDeployment($host, $user);
    }

    /**
     * True when the host belongs to a thread that is not team-visible. A share
     * token or share cookie identifies nobody, so it never opens such a host.
     */
    public function refusesShareCredentials(string $host): bool
    {
        $thread = $this->threadFor($host) ?? $this->threadForDeployment($host);

        return $thread !== null && $thread->effectiveVisibility() !== ThreadVisibility::Team;
    }

    private function mayEnterDeployment(string $host, User $user): bool
    {
        $thread = $this->threadForDeployment($host);

        return $thread === null || app(ThreadAccess::class)->canSee($user, $thread);
    }

    private function threadForDeployment(string $host): ?Thread
    {
        $taskId = BranchDeployment::withoutGlobalScopes()->where('hostname', strtolower($host))->value('yak_task_id');
        $threadId = $taskId === null ? null : YakTask::withoutGlobalScopes()->whereKey($taskId)->value('thread_id');

        return $threadId === null ? null : Thread::withoutGlobalScopes()->whereKey($threadId)->first();
    }

    private function cookieUserId(string $host, string $value): ?int
    {
        $parts = $this->open('cookie', $value);
        if ($parts === null || count($parts) !== 3) {
            return null;
        }

        [$cookieHost, $userId, $expiresAt] = $parts;

        return $cookieHost === strtolower($host) && (int) $expiresAt >= now()->getTimestamp() ? (int) $userId : null;
    }

    /**
     * Raw Set-Cookie header: host-only (no Domain), so the browser never
     * sends it to the dashboard or to sibling previews.
     */
    public function setCookieHeader(string $host, int $userId): string
    {
        $value = $this->seal('cookie', [strtolower($host), $userId, now()->getTimestamp() + self::COOKIE_TTL_SECONDS]);

        return sprintf(
            '%s=%s; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=%d',
            self::COOKIE_NAME,
            $value,
            self::COOKIE_TTL_SECONDS,
        );
    }

    /**
     * @param  array<int, string|int>  $parts
     */
    private function seal(string $purpose, array $parts): string
    {
        $payload = rtrim(strtr(base64_encode(implode('|', $parts)), '+/', '-_'), '=');

        return $payload . '.' . $this->signature($purpose, $payload);
    }

    /**
     * @return array<int, string>|null
     */
    private function open(string $purpose, string $value): ?array
    {
        $segments = explode('.', $value);
        if (count($segments) !== 2 || ! hash_equals($this->signature($purpose, $segments[0]), $segments[1])) {
            return null;
        }

        $decoded = base64_decode(strtr($segments[0], '-_', '+/'), true);

        return $decoded === false ? null : explode('|', $decoded);
    }

    private function signature(string $purpose, string $payload): string
    {
        return hash_hmac('sha256', $purpose . '.' . $payload, (string) config('app.key'));
    }
}
