<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Services\CiFailure\CiFailureIntake;
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

    public function handle(AppService $github, CiFailureIntake $intake): void
    {
        $repository = Repository::find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $run = $github->getWorkflowRun(
            (int) config('yak.channels.github.installation_id'),
            (string) $repository->github_full_name,
            (int) data_get($this->payload, 'workflow_job.run_id'),
        );

        if ($run['head_repository_full_name'] !== $repository->github_full_name
            || $run['head_sha'] !== (string) data_get($this->payload, 'workflow_job.head_sha')) {
            return;
        }

        $intake->recordGreen($repository, Request::create('/', 'POST', $this->payload));
    }
}
