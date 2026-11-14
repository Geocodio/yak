<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Teleport\LocalTurns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocalTurnsController extends Controller
{
    public function __invoke(Request $request, Thread $thread, LocalTurns $localTurns): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $data = $request->validate(['before' => ['required', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return response()->json($localTurns->before($thread, (int) $data['before'], (int) ($data['limit'] ?? 25)));
    }
}
