<?php

namespace App\Jobs;

use App\Actions\StartSecondRound;
use App\Channels\GitHub\AppService;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QuietPeriodReviewJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $repositoryId,
        public readonly int $prNumber,
        public readonly string $headSha,
    ) {
        $this->onQueue('default');
    }

    public function handle(AppService $github, StartSecondRound $startSecondRound): void
    {
        $repository = Repository::find($this->repositoryId);

        if ($repository === null || ! $repository->is_active) {
            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $pullRequest = $github->getPullRequestOrFail($installationId, $repository->github_full_name, $this->prNumber);

        if ($pullRequest === null || ($pullRequest['head']['sha'] ?? null) !== $this->headSha) {
            return;
        }

        if (! $github->allCheckRunsCompleted($installationId, $repository->github_full_name, $this->headSha)) {
            return;
        }

        $startSecondRound->handle($repository, $pullRequest, 'quiet_period');
    }
}
