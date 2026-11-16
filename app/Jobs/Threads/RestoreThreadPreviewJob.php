<?php

namespace App\Jobs\Threads;

use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RestoreThreadPreviewJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Thread $thread) {}

    public function handle(ThreadSandbox $sandbox, ThreadPreviewDevServer $devServer): void
    {
        try {
            if ($sandbox->restore($this->thread)) {
                $devServer->ensureStarted($this->thread->refresh());
            }
        } finally {
            Cache::forget("thread-preview:restore:{$this->thread->id}");
        }
    }
}
