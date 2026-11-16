<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('the environment rows show the pull request badge and the deployment host', function () {
    $thread = Thread::factory()->create(['branch' => 'yak/importer', 'repo' => 'acme/widgets']);
    YakTask::factory()->success()->create(['thread_id' => $thread->id, 'pr_url' => 'https://github.com/acme/widgets/pull/12']);
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);
    BranchDeployment::factory()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/importer',
        'hostname' => 'importer.preview.example.com',
        'status' => DeploymentStatus::Running,
    ]);

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="environment-pull-request"]', '#12')
        ->assertSeeIn('[data-testid="environment-pull-request"]', 'Open')
        ->assertSeeIn('[data-testid="environment-deployment"]', 'importer.preview.example.com')
        ->assertSeeIn('[data-testid="environment-branch"]', 'yak/importer');
});

test('the environment rows explain empty values', function () {
    $thread = Thread::factory()->create();

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="environment-pull-request"]', 'Not opened yet')
        ->assertSeeIn('[data-testid="environment-deployment"]', 'None for this branch');
});
