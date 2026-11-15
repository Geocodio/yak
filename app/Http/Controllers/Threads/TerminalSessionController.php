<?php

namespace App\Http\Controllers\Threads;

use App\DataTransferObjects\TerminalSessionResult;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Ssh\GitHubKeyDirectory;
use App\Services\Ssh\TerminalSessions;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadAccess;
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
        TerminalSessions $sessions,
        GitHubKeyDirectory $github,
        SandboxCapacity $capacity,
    ): JsonResponse {
        $user = $request->user();
        $thread = Thread::withoutGlobalScopes()->where('public_id', $thread)->first();

        abort_if($user === null || $thread === null || ! $threadAccess->canSee($user, $thread), 404);

        $origin = (string) $request->headers->get('Origin');

        $result = $sessions->open($user, $thread, $origin);

        if ($result->outcome !== TerminalSessionResult::REFUSED) {
            $github->refresh($user);
        }

        return match ($result->outcome) {
            TerminalSessionResult::READY => response()->json(['url' => $result->url, 'token' => $result->token, 'expires_at' => $result->expiresAt]),
            TerminalSessionResult::REFUSED => response()->json(['error' => $result->refusal], $result->refusal === 'origin_not_allowed' ? 403 : (self::REFUSALS[(string) $result->refusal] ?? 403)),
            TerminalSessionResult::WAITING => response()->json(['state' => 'waiting', 'limit' => $result->limit, 'restoring' => $result->restoring], 202),
            default => response()->json(['state' => 'waiting', 'limit' => $capacity->needsEviction($thread)?->value, 'restoring' => false], 202),
        };
    }
}
