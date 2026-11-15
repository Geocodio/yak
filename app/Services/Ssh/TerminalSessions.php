<?php

namespace App\Services\Ssh;

use App\DataTransferObjects\TerminalSessionResult;
use App\Enums\ThreadBlockReason;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadRecovery;
use App\Services\Threads\ThreadSandbox;

/**
 * The one rule for opening a shell in a thread's sandbox, shared by the web and the app.
 */
class TerminalSessions
{
    public function __construct(
        private readonly SshAccess $access,
        private readonly ThreadSandbox $sandbox,
        private readonly TerminalTokens $tokens,
        private readonly SandboxCapacity $capacity,
    ) {}

    /**
     * A web caller restores a failed sandbox inside the request (`$restoresInline`); the app reports it instead.
     */
    public function open(User $user, Thread $thread, string $origin, bool $restoresInline = true): TerminalSessionResult
    {
        if (! in_array($origin, $this->allowedOrigins(), true)) {
            return TerminalSessionResult::refused('origin_not_allowed');
        }

        $denial = $this->access->denial($user, $thread);

        if (in_array($denial, ['forbidden', 'thread_closed', 'thread_owned_by_device'], true)) {
            return TerminalSessionResult::refused($denial);
        }

        if (ThreadRecovery::isRestoreQueued($thread)) {
            $limit = $thread->blocked_reason === ThreadBlockReason::WaitingForSandbox
                ? $this->capacity->needsEviction($thread)?->value
                : null;

            return TerminalSessionResult::waiting($limit, restoring: true);
        }

        if (! $restoresInline && $thread->blocked_reason === ThreadBlockReason::RestoreFailed) {
            return TerminalSessionResult::restoreFailed();
        }

        if ($denial !== null) {
            return TerminalSessionResult::refused($denial);
        }

        $wake = $this->sandbox->ensureAwake($thread);

        if ($wake->ready) {
            $token = $this->tokens->issue($user, $thread, $origin);

            return TerminalSessionResult::ready(
                self::socketUrl() . '?token=' . $token,
                $token,
                now()->addSeconds((int) config('yak.ssh.terminal_token_ttl'))->toIso8601String(),
            );
        }

        if ($wake->waitingForSlot || $wake->restoring) {
            return TerminalSessionResult::waiting($this->capacity->needsEviction($thread)?->value, restoring: $wake->restoring);
        }

        return TerminalSessionResult::restoreFailed();
    }

    /**
     * @return list<string>
     */
    public function allowedOrigins(): array
    {
        $parts = parse_url((string) config('app.url'));
        $appOrigin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : null;

        return array_values(array_filter([...(array) config('yak.ssh.terminal_allowed_origins'), $appOrigin]));
    }

    public static function socketUrl(): string
    {
        $configured = config('yak.ssh.terminal_ws_url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return preg_replace('/^http/', 'ws', rtrim((string) config('app.url'), '/')) . '/terminal/ws';
    }
}
