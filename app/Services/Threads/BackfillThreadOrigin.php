<?php

namespace App\Services\Threads;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSource;
use App\Models\Thread;

/**
 * Fills the origin columns of threads that predate them. Safe to run again:
 * a thread with no request keeps a source it already carries.
 */
class BackfillThreadOrigin
{
    public function __construct(private readonly ThreadOrigin $origin) {}

    public function __invoke(): void
    {
        Thread::query()->withoutGlobalScopes()->with('tasks')->chunkById(200, function ($threads): void {
            foreach ($threads as $thread) {
                $this->backfill($thread);
            }
        });
    }

    private function backfill(Thread $thread): void
    {
        $firstTask = $thread->tasks->sortBy('id')->first();

        if ($firstTask === null) {
            if ($thread->source === ThreadSource::Dashboard) {
                $thread->source = match (true) {
                    $thread->parent_thread_id !== null => ThreadSource::Stack,
                    $thread->owner_side === ThreadOwnerSide::Device => ThreadSource::Teleport,
                    default => ThreadSource::Dashboard,
                };
                $thread->saveQuietly();
            }

            return;
        }

        $request = $firstTask->rootTask();
        $thread->forceFill($this->origin->forRequest($request));

        if ($thread->created_by_user_id === null && $request->started_by_user_id !== null) {
            $thread->created_by_user_id = $request->started_by_user_id;
            $thread->starter_name = null;
        }

        $thread->saveQuietly();
    }
}
