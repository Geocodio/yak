<?php

namespace App\Jobs;

use App\Models\YakTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunYakReviewJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public YakTask $task)
    {
        $this->onQueue('yak-claude');
    }

    public function handle(): void
    {
        // Implemented in Task 1.11.
    }
}
