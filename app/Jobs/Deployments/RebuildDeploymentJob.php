<?php

namespace App\Jobs\Deployments;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Observers\BranchDeploymentObserver;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RebuildDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deploymentId)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $deployment = BranchDeployment::with('repository')->findOrFail($this->deploymentId);

        try {
            $this->transition($deployment, DeploymentStatus::Destroying);

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Destroying container for rebuild');
            $manager->destroy($deployment);

            $this->transition($deployment, DeploymentStatus::Starting, [
                'template_version' => (int) $deployment->repository->current_template_version,
            ]);

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Cloning template snapshot v' . $deployment->template_version);
            $manager->createFromTemplate($deployment);

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Starting container');
            $manager->start($deployment, $this->resolveHeadSha($deployment));

            $deployment->status = DeploymentStatus::Running;
            $deployment->last_accessed_at = now();
            $deployment->save();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Deployment ready');
        } catch (Throwable $e) {
            $this->transition($deployment, DeploymentStatus::Failed, [
                'failure_reason' => 'rebuild: ' . $e->getMessage(),
            ]);
            DeploymentLog::record($deployment, 'error', 'lifecycle', 'Rebuild failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Moves the deployment to a status with a raw update, because a rebuild
     * passes through Destroying to Starting and Failed, which the state
     * machine does not allow, then reports the change through the observer.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(BranchDeployment $deployment, DeploymentStatus $status, array $attributes = []): void
    {
        $from = $deployment->status;
        $previousChange = $deployment->updated_at;

        BranchDeployment::query()->where('id', $deployment->id)->update(['status' => $status->value, ...$attributes]);
        $deployment->refresh();

        app(BranchDeploymentObserver::class)->statusChanged($deployment, $from, $previousChange);
    }

    /**
     * The commit to rebuild onto: the branch's current head, not the sha
     * stored when the deployment was last touched.
     *
     * A rebuild can be triggered long after that sha was recorded, and a
     * rebased or force-pushed branch leaves it pointing at a commit the
     * remote no longer has — `git checkout` then dies with "reference is
     * not a tree" and the rebuild fails for a reason that has nothing to
     * do with the preview. Re-resolve by branch so this works whether or
     * not `pr_number` was ever populated, and keep the stored sha when
     * there is no open PR or GitHub cannot be reached.
     */
    private function resolveHeadSha(BranchDeployment $deployment): ?string
    {
        try {
            $pr = app(GitHubAppService::class)->findOpenPullRequestForBranch(
                (int) config('yak.channels.github.installation_id'),
                (string) $deployment->repository->github_full_name,
                (string) $deployment->branch_name,
            );

            $head = $pr['head']['sha'] ?? null;

            if (is_string($head) && $head !== '') {
                $deployment->forceFill(['current_commit_sha' => $head])->save();

                return $head;
            }
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Rebuild could not resolve branch head; using stored sha', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $deployment->current_commit_sha;
    }
}
