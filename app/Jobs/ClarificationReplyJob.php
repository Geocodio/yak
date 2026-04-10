<?php

namespace App\Jobs;

use App\Models\YakTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClarificationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly string $replyText,
    ) {
        $this->onQueue('yak-claude');
    }

    public function handle(): void
    {
        //
    }
}
