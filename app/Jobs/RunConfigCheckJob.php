<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\RepositoryConfigCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Posts the `yak / config` check run for a pull request head commit.
 */
class RunConfigCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $repositoryId,
        public readonly int $pullRequestNumber,
        public readonly string $headSha,
    ) {
        $this->onQueue('default');
    }

    public function handle(RepositoryConfigCheck $check): void
    {
        $repository = Repository::find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $check->run($repository, $this->pullRequestNumber, $this->headSha);
    }
}
