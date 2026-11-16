<?php

namespace App\Http\Controllers\Api\Cli;

use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Services\Teleport\ThreadApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadLookupController extends Controller
{
    use ResolvesCliThread;

    public function show(Request $request, string $thread): JsonResponse
    {
        return $this->respond($request, $this->cliThread($request, $thread));
    }

    public function bySession(Request $request, string $sessionId): JsonResponse
    {
        $threadId = ThreadSession::query()->where('session_id', $sessionId)->value('thread_id');

        return $this->respond($request, $this->visibleCliThread($request, $threadId === null ? null : Thread::withoutGlobalScopes()->whereKey($threadId)->first()));
    }

    private function respond(Request $request, Thread $thread): JsonResponse
    {
        return response()->json(['thread' => app(ThreadApiPayload::class)->build($thread, $this->cliDevice($request))]);
    }
}
