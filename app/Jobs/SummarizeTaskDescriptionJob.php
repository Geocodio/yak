<?php

namespace App\Jobs;

use App\Models\YakTask;
use App\Services\TaskDescriptionSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SummarizeTaskDescriptionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public readonly YakTask $task)
    {
        $this->onQueue('default');
    }

    public function handle(TaskDescriptionSummary $summaries): void
    {
        $summary = $summaries->generate((string) $this->task->description);

        if ($summary !== null) {
            $this->task->update(['description_summary' => $summary]);
        }
    }
}
