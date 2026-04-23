<?php

namespace App\Services;

use App\Models\BranchDeployment;
use Illuminate\Support\Facades\Process;

class DeploymentContainerManager
{
    public function createFromTemplate(BranchDeployment $deployment): void
    {
        $deployment->loadMissing('repository');
        $snapshot = sprintf(
            'yak-tpl-%s/ready-v%d',
            $deployment->repository->slug,
            $deployment->template_version,
        );

        $result = Process::run("incus copy {$snapshot} {$deployment->container_name}");

        if ($result->exitCode() !== 0) {
            throw new \RuntimeException("Failed to clone template snapshot: {$result->errorOutput()}");
        }
    }
}
