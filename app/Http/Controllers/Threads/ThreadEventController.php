<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Services\Threads\ThreadEventProjector;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Catch-up for a browser that missed realtime events or received a pointer. A hidden thread
 * answers exactly like a missing one.
 */
class ThreadEventController extends Controller
{
    public const MAX_LIMIT = 500;

    public function index(Request $request, Thread $thread, ThreadEventWindow $window): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $after = max(0, $request->integer('after'));
        $limit = min(self::MAX_LIMIT, max(1, $request->integer('limit', self::MAX_LIMIT)));

        $events = $window->since($thread->id, $after, $limit);
        $source = 'window';

        if ($events === null) {
            $source = 'database';
            $events = ThreadEvent::query()
                ->where('thread_id', $thread->id)
                ->where('sequence', '>', $after)
                ->orderBy('sequence')
                ->limit($limit)
                ->get()
                ->map(fn (ThreadEvent $event): array => $this->shape($event))
                ->all();
        }

        return response()->json([
            'events' => $events,
            'latestSequence' => (int) Thread::withoutGlobalScopes()->whereKey($thread->id)->value('event_sequence'),
            'source' => $source,
        ]);
    }

    public function show(Thread $thread, int $sequence): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $event = ThreadEvent::query()->where('thread_id', $thread->id)->where('sequence', $sequence)->firstOrFail();

        return response()->json($this->shape($event));
    }

    /**
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    private function shape(ThreadEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'type' => $event->type,
            'data' => $event->type === 'entry' ? app(ThreadEventProjector::class)->withHtml($event->payload) : $event->payload,
            'createdAt' => $event->created_at->toIso8601String(),
        ];
    }
}
