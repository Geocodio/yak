<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleCiJobFailureJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $workflowJobPayload  the full workflow_job webhook payload
     */
    public function __construct(
        public int $repositoryId,
        public array $workflowJobPayload,
    ) {}

    public function handle(): void {}
}
