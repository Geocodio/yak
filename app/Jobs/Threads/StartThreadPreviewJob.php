<?php

namespace App\Jobs\Threads;

use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StartThreadPreviewJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Thread $thread) {}

    public function handle(ThreadPreviewDevServer $devServer): void
    {
        $devServer->runColdStart($this->thread);
    }
}
