<?php

namespace App\Jobs;

use App\Enums\RepositorySetupStatus;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets one extra repository up again after a failure and tells the agent when it is ready.
 */
class RetryRepositorySetupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Thread $thread, public readonly ThreadRepository $repository) {}

    public function handle(ExtraRepositorySetup $setup, ThreadTurns $turns): void
    {
        $result = $setup->setUp($this->thread, $this->repository);

        if ($result->status === RepositorySetupStatus::Ready) {
            $turns->steer($this->thread, "Setup for {$this->repository->repo} finished. You can continue.", null, null);
        }
    }
}
