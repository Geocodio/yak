<?php

namespace App\Actions;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Jobs\Deployments\DeployBranchJob;
use App\Models\Repository;
use App\Services\BranchDeploymentProvisioner;

class BackfillOpenPrDeployments
{
    public function __construct(
        private GitHubAppService $github,
        private BranchDeploymentProvisioner $provisioner,
    ) {}

    /**
     * Provision (and deploy) a branch preview for every open PR on the
     * repository that does not already have one, returning the number of
     * deployments created.
     *
     * Idempotent and gap-filling only: a PR whose branch already has a
     * deployment in any state (running, hibernated, failed, even destroyed)
     * is left untouched. Unlike PR review, drafts and Yak-authored PRs are
     * NOT skipped, matching the `pull_request.opened` webhook which deploys
     * them too.
     */
    public function __invoke(Repository $repository): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $cap = (int) config('yak.deployments.running_cap', 6);

        $prs = $this->github->listOpenPullRequests($installationId, $repository->github_full_name);

        $created = 0;

        foreach ($prs as $pr) {
            $branch = (string) ($pr['head']['ref'] ?? '');

            if ($branch === '') {
                continue;
            }

            $deployment = $this->provisioner->provision($repository, $branch);

            // firstOrCreate matches on repository_id + branch_name, so an
            // existing row returns wasRecentlyCreated=false. Only fill gaps.
            if (! $deployment->wasRecentlyCreated) {
                continue;
            }

            $deployment->update([
                'pr_number' => (int) ($pr['number'] ?? 0),
                'pr_state' => 'open',
                'current_commit_sha' => (string) ($pr['head']['sha'] ?? ''),
            ]);

            // Stagger boots past the running cap so enabling a repo with many
            // open PRs doesn't bring up every sandbox at once (mirrors
            // RebuildRepositoryDeploymentsJob).
            $dispatch = DeployBranchJob::dispatch($deployment->id);
            $delaySeconds = max(0, $created - $cap + 1) * 60;
            if ($delaySeconds > 0) {
                $dispatch->delay(now()->addSeconds($delaySeconds));
            }

            $created++;
        }

        return $created;
    }
}
