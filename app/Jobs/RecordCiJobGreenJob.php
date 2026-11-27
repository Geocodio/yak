<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Services\CiFailure\CiFailureIntake;
use App\Services\DefaultBranchCi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;

/**
 * Records a passing job once the run is confirmed to come from the repository
 * itself at the reported commit. The webhook payload alone cannot tell a fork
 * branch from a same-named branch of the repository.
 */
class RecordCiJobGreenJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload  the full workflow_job webhook payload
     */
    public function __construct(
        public int $repositoryId,
        public array $payload,
    ) {}

    public function handle(AppService $github, CiFailureIntake $intake, DefaultBranchCi $defaultBranchCi): void
    {
        $repository = Repository::find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        $run = $github->getWorkflowRun(
            $installationId,
            (string) $repository->github_full_name,
            (int) data_get($this->payload, 'workflow_job.run_id'),
        );

        if ($run['head_repository_full_name'] !== $repository->github_full_name
            || ! in_array($run['event'], CiFailureIntake::TRUSTED_EVENTS, true)
            || $run['head_sha'] !== (string) data_get($this->payload, 'workflow_job.head_sha')) {
            return;
        }

        $branch = (string) data_get($this->payload, 'workflow_job.head_branch');

        if ($branch !== $repository->default_branch && $github->isTag($installationId, (string) $repository->github_full_name, $branch)) {
            return;
        }

        $intake->recordGreen($repository, Request::create('/', 'POST', $this->payload));

        if ($branch === $repository->default_branch) {
            $defaultBranchCi->unparkReadyTasks($repository);
        }
    }
}
