<?php

namespace App\Services\Ssh;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * One-time tickets for the terminal WebSocket. The cache holds only the sha256 of the token, so a cache dump cannot
 * be replayed, and redeeming consumes the ticket whether or not the origin matches.
 */
class TerminalTokens
{
    public function issue(User $user, Thread $thread, string $origin): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Cache::put($this->key($token), [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'origin' => $origin,
        ], (int) config('yak.ssh.terminal_token_ttl'));

        return $token;
    }

    public function redeem(string $token, string $origin): ?TerminalGrant
    {
        $payload = Cache::pull($this->key($token));

        if (! is_array($payload) || ! hash_equals((string) $payload['origin'], $origin)) {
            return null;
        }

        return new TerminalGrant((int) $payload['user_id'], (int) $payload['thread_id'], $origin);
    }

    private function key(string $token): string
    {
        return 'ssh:terminal-token:' . hash('sha256', $token);
    }
}
