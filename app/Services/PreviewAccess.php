<?php

namespace App\Services;

use App\Models\User;
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
        return $this->seal('ticket', [Str::random(24), strtolower($host), $user->id, now()->timestamp + self::TICKET_TTL_SECONDS]);
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
        if ($ticketHost !== strtolower($host) || (int) $expiresAt < now()->timestamp) {
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
        $parts = $this->open('cookie', $value);
        if ($parts === null || count($parts) !== 3) {
            return false;
        }

        [$cookieHost, $userId, $expiresAt] = $parts;

        return $cookieHost === strtolower($host)
            && (int) $expiresAt >= now()->timestamp
            && User::whereKey((int) $userId)->exists();
    }

    /**
     * Raw Set-Cookie header: host-only (no Domain), so the browser never
     * sends it to the dashboard or to sibling previews.
     */
    public function setCookieHeader(string $host, int $userId): string
    {
        $value = $this->seal('cookie', [strtolower($host), $userId, now()->timestamp + self::COOKIE_TTL_SECONDS]);

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
