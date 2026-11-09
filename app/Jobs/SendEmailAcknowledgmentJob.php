<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEmailAcknowledgmentJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $taskIds
     */
    public function __construct(public array $taskIds) {}

    public function handle(): void {}
}
