<?php

namespace App\Services;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Support\BranchHostname;
use Illuminate\Support\Str;

class BranchDeploymentProvisioner
{
    public function provision(Repository $repository, string $branchName): BranchDeployment
    {
        return BranchDeployment::firstOrCreate(
            [
                'repository_id' => $repository->id,
                'branch_name' => $branchName,
            ],
            [
                'hostname' => $this->uniqueHostname($repository, $branchName),
                'container_name' => 'deploy-' . Str::lower((string) Str::ulid()),
                'template_version' => max(1, $repository->current_template_version ?? 0),
                'status' => DeploymentStatus::Pending,
            ],
        );
    }

    private function uniqueHostname(Repository $repository, string $branchName): string
    {
        $suffix = (string) config('yak.deployments.hostname_suffix');
        $natural = BranchHostname::build($repository->slug, $branchName, $suffix);

        $exists = BranchDeployment::where('hostname', $natural)->exists();

        return $exists
            ? BranchHostname::withCollisionSuffix($repository->slug, $branchName, $suffix)
            : $natural;
    }
}
