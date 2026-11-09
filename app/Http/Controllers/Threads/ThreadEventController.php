<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catch-up for a browser that missed realtime events or received a pointer.
 */
class ThreadEventController extends Controller
{
    public function index(Request $request, Thread $thread): JsonResponse
    {
        $after = max(0, $request->integer('after'));
        $limit = min(500, max(1, $request->integer('limit', 500)));

        $events = ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->where('sequence', '>', $after)
            ->orderBy('sequence')
            ->limit($limit)
            ->get()
            ->map(fn (ThreadEvent $event): array => [
                'sequence' => $event->sequence,
                'type' => $event->type,
                'data' => $event->payload,
                'createdAt' => $event->created_at->toIso8601String(),
            ]);

        return response()->json([
            'events' => $events,
            'latestSequence' => (int) $thread->event_sequence,
        ]);
    }
}
