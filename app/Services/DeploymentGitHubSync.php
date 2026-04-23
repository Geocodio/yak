<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;

class DeploymentGitHubSync
{
    public function __construct(private readonly AppService $github) {}

    public function sync(BranchDeployment $deployment, DeploymentStatus $newStatus): void
    {
        $deployment->loadMissing('repository');

        $installationId = (int) config('yak.channels.github.installation_id');
        $repoSlug = $deployment->repository->slug;
        $environmentUrl = 'https://' . $deployment->hostname;
        $environment = 'preview/' . $deployment->branch_name;

        // First-time: create the GH deployment record when transitioning to Starting.
        if ($deployment->github_deployment_id === null && $newStatus === DeploymentStatus::Starting) {
            if ($deployment->current_commit_sha === null) {
                return;
            }

            $ghId = $this->github->createDeployment(
                installationId: $installationId,
                repoSlug: $repoSlug,
                ref: $deployment->current_commit_sha,
                environment: $environment,
                description: 'Yak preview deployment',
            );

            $deployment->github_deployment_id = $ghId;
            $deployment->save();

            $this->github->createDeploymentStatus(
                installationId: $installationId,
                repoSlug: $repoSlug,
                deploymentId: $ghId,
                state: 'in_progress',
                environmentUrl: $environmentUrl,
                logUrl: null,
                description: 'Provisioning preview',
            );

            return;
        }

        if ($deployment->github_deployment_id === null) {
            return;
        }

        $state = match ($newStatus) {
            DeploymentStatus::Starting => 'in_progress',
            DeploymentStatus::Running => 'success',
            DeploymentStatus::Hibernated => 'inactive',
            DeploymentStatus::Failed => 'failure',
            DeploymentStatus::Destroying, DeploymentStatus::Destroyed => 'inactive',
            default => null,
        };

        if ($state === null) {
            return;
        }

        $this->github->createDeploymentStatus(
            installationId: $installationId,
            repoSlug: $repoSlug,
            deploymentId: $deployment->github_deployment_id,
            state: $state,
            environmentUrl: $state === 'success' ? $environmentUrl : null,
            logUrl: null,
            description: $newStatus === DeploymentStatus::Failed ? ($deployment->failure_reason ?? '') : '',
        );

        if ($newStatus === DeploymentStatus::Destroyed) {
            $this->github->deleteDeployment($installationId, $repoSlug, $deployment->github_deployment_id);
            $deployment->github_deployment_id = null;
            $deployment->save();
        }
    }
}
