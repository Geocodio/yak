<?php

namespace App\Jobs\Threads;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshSiblingBlocksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $threadId)
    {
        $this->onQueue('default');
    }

    public function handle(): void {}
}
