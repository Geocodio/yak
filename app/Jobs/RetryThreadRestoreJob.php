<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RetryThreadRestoreJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Thread $thread) {}

    public function handle(ThreadSandbox $sandbox): void
    {
        $sandbox->restore($this->thread);
    }
}
