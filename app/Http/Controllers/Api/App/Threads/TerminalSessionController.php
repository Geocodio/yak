<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\DataTransferObjects\TerminalSessionResult;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Ssh\TerminalSessions;
use App\Services\Threads\ThreadRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TerminalSessionController extends Controller
{
    /** @var array<string, array{0: int, 1: string}> status and sentence per refusal code */
    private const REFUSALS = [
        ThreadActionRefused::ORIGIN_NOT_ALLOWED => [403, 'This Yak does not accept terminal connections from this app.'],
        'forbidden' => [403, 'You do not have access to this.'],
        ThreadActionRefused::THREAD_CLOSED => [409, ThreadRecovery::CLOSED_SENTENCE],
        ThreadActionRefused::THREAD_OWNED_BY_DEVICE => [409, 'This thread runs on a computer.'],
        ThreadActionRefused::SANDBOX_DESTROYED => [409, 'The sandbox was removed. Restore it to open a shell.'],
        ThreadActionRefused::NO_SANDBOX => [409, 'This thread has no sandbox yet.'],
    ];

    /**
     * Answers `{url, token, expires_at}` for a ready shell and `202 {state: "waiting", limit, restoring}` while the
     * sandbox wakes, waits for a slot or is restored; the app asks again.
     */
    public function store(Request $request, Thread $thread, TerminalSessions $sessions): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $result = $sessions->open($request->user() ?? abort(401), $thread, (string) $request->headers->get('Origin'), restoresInline: false);

        return match ($result->outcome) {
            TerminalSessionResult::READY => response()->json(['url' => $result->url, 'token' => $result->token, 'expires_at' => $result->expiresAt]),
            TerminalSessionResult::WAITING => response()->json(['state' => 'waiting', 'limit' => $result->limit, 'restoring' => $result->restoring], 202),
            TerminalSessionResult::REFUSED => throw $this->refusal((string) $result->refusal),
            default => throw ThreadActionRefused::because(ThreadActionRefused::THREAD_BLOCKED, 'The sandbox could not be restored.', ['reason' => 'restore_failed']),
        };
    }

    private function refusal(string $code): ThreadActionRefused
    {
        [$status, $message] = self::REFUSALS[$code] ?? self::REFUSALS['forbidden'];

        return new ThreadActionRefused($code, $message, status: $status);
    }
}
