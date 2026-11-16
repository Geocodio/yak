<?php

namespace App\Services\Threads\Preview;

use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Cache;

/**
 * Preview traffic keeps a sandbox awake: while a request landed inside the
 * activity window, the idle pause leaves the sandbox alone.
 */
class ThreadPreviewActivity
{
    private const THROTTLE_SECONDS = 30;

    public function record(Thread $thread): void
    {
        if (Cache::add("thread-preview:activity:{$thread->id}", true, self::THROTTLE_SECONDS)) {
            $thread->forceFill(['preview_last_request_at' => now()])->save();
        }

        app(ThreadSandbox::class)->touch($thread);
    }

    /** True when this thread, or one sharing its container, had a preview request inside the window. */
    public function isActive(Thread $thread): bool
    {
        $cutoff = now()->subMinutes((int) config('yak.threads.preview_activity_minutes'));

        return Thread::withoutGlobalScopes()
            ->when(
                $thread->sandbox_container !== null,
                fn ($query) => $query->where('sandbox_container', $thread->sandbox_container),
                fn ($query) => $query->whereKey($thread->id),
            )
            ->where('preview_last_request_at', '>=', $cutoff)
            ->exists();
    }
}
