<?php

use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake();
});

it('clones from the pinned template snapshot', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-repo',
        'current_template_version' => 5,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
        'template_version' => 5,
    ]);

    app(DeploymentContainerManager::class)->createFromTemplate($deployment);

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus copy yak-tpl-example-repo/ready-v5 deploy-42')
    );
});

it('uses the deployment template_version (not repo current)', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-repo',
        'current_template_version' => 9,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
        'template_version' => 3,
    ]);

    app(DeploymentContainerManager::class)->createFromTemplate($deployment);

    Process::assertRan(fn ($process) => str_contains($process->command, 'yak-tpl-example-repo/ready-v3')
    );
});
