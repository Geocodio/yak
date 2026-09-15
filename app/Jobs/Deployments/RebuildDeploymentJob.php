<?php

namespace App\Jobs\Deployments;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
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
            BranchDeployment::query()->where('id', $deployment->id)->update(['status' => DeploymentStatus::Destroying->value]);
            $deployment->refresh();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Destroying container for rebuild');
            $manager->destroy($deployment);

            $deployment->template_version = (int) $deployment->repository->current_template_version;
            BranchDeployment::query()->where('id', $deployment->id)->update([
                'status' => DeploymentStatus::Starting->value,
                'template_version' => $deployment->template_version,
            ]);
            $deployment->refresh();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Cloning template snapshot v' . $deployment->template_version);
            $manager->createFromTemplate($deployment);

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Starting container');
            $manager->start($deployment);

            $commitSha = $this->resolveHeadSha($deployment);

            if ($commitSha !== null) {
                $manager->applyCheckoutRefresh($deployment, $commitSha);
            }

            $deployment->status = DeploymentStatus::Running;
            $deployment->last_accessed_at = now();
            $deployment->save();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Deployment ready');
        } catch (Throwable $e) {
            BranchDeployment::query()->where('id', $deployment->id)->update([
                'status' => DeploymentStatus::Failed->value,
                'failure_reason' => 'rebuild: ' . $e->getMessage(),
            ]);
            DeploymentLog::record($deployment, 'error', 'lifecycle', 'Rebuild failed: ' . $e->getMessage());
            throw $e;
        }
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
