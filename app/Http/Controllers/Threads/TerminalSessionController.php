<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Ssh\GitHubKeyDirectory;
use App\Services\Ssh\SshAccess;
use App\Services\Ssh\TerminalTokens;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalSessionController extends Controller
{
    private const REFUSALS = [
        'forbidden' => 403,
        'thread_closed' => 409,
        'thread_owned_by_device' => 409,
        'sandbox_destroyed' => 409,
        'no_sandbox' => 409,
    ];

    /**
     * Answers {url, token, expires_at}; the url already carries the token. A 202 {state: "waiting"} means the sandbox
     * is queued for a slot and the page should ask again; `limit` names the cap that is full (person_share, global_cap,
     * memory_floor) or is null when the slot freed up in the meantime.
     */
    public function store(
        Request $request,
        string $thread,
        ThreadAccess $threadAccess,
        SshAccess $access,
        ThreadSandbox $sandbox,
        TerminalTokens $tokens,
        GitHubKeyDirectory $github,
        SandboxCapacity $capacity,
    ): JsonResponse {
        $user = $request->user();
        $thread = Thread::withoutGlobalScopes()->where('public_id', $thread)->first();

        abort_if($user === null || $thread === null || ! $threadAccess->canSee($user, $thread), 404);

        $origin = (string) $request->headers->get('Origin');

        if (! in_array($origin, $this->allowedOrigins(), true)) {
            return response()->json(['error' => 'origin_not_allowed'], 403);
        }

        $denial = $access->denial($user, $thread);

        if ($denial !== null) {
            return response()->json(['error' => $denial], self::REFUSALS[$denial] ?? 403);
        }

        $github->refresh($user);

        if (! $sandbox->ensureAwake($thread)->ready) {
            return response()->json(['state' => 'waiting', 'limit' => $capacity->needsEviction($thread)?->value], 202);
        }

        $token = $tokens->issue($user, $thread, $origin);

        return response()->json([
            'url' => $this->socketUrl() . '?token=' . $token,
            'token' => $token,
            'expires_at' => now()->addSeconds((int) config('yak.ssh.terminal_token_ttl'))->toIso8601String(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $parts = parse_url((string) config('app.url'));
        $appOrigin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : null;

        return array_values(array_filter([...(array) config('yak.ssh.terminal_allowed_origins'), $appOrigin]));
    }

    private function socketUrl(): string
    {
        $configured = config('yak.ssh.terminal_ws_url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return preg_replace('/^http/', 'ws', rtrim((string) config('app.url'), '/')) . '/terminal/ws';
    }
}
