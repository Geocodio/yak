<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\Stacks\StopStack;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stops every turn of a stack off the request that asked for it. Safe to run any number of times.
 */
final class StopStackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $rootThreadId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(StopStack $stopStack): void
    {
        $root = Thread::query()->withoutGlobalScopes()->find($this->rootThreadId);

        if ($root === null) {
            return;
        }

        $stopStack->stop($root);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('A stack could not be stopped', ['root' => $this->rootThreadId, 'error' => $exception::class]);
    }
}
