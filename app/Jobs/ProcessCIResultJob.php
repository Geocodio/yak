<?php

namespace App\Jobs;

use App\Models\YakTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCIResultJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly bool $passed,
        public readonly ?string $output = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        //
    }
}
