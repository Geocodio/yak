<?php

use App\Livewire\Tasks\TaskDetail;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows a preview button in the header when the task branch has an active deployment', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app']);
    BranchDeployment::factory()->running()->create([
        'repository_id' => $repo->id,
        'branch_name' => 'feat/foo',
        'hostname' => 'acme-app-feat-foo.yak.example.com',
    ]);

    $task = YakTask::factory()->create([
        'repo' => 'acme/app',
        'branch_name' => 'feat/foo',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('data-testid="preview-button"')
        ->assertSee('acme-app-feat-foo.yak.example.com');
});

it('hides the preview button when the task has no branch', function () {
    $task = YakTask::factory()->create(['branch_name' => null]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertDontSeeHtml('data-testid="preview-button"');
});

it('hides the preview button when no matching deployment exists', function () {
    Repository::factory()->create(['slug' => 'acme/app']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/app',
        'branch_name' => 'feat/nothing-deployed',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertDontSeeHtml('data-testid="preview-button"');
});

it('hides the preview button for destroyed deployments', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app']);
    BranchDeployment::factory()->destroyed()->create([
        'repository_id' => $repo->id,
        'branch_name' => 'feat/stale',
        'hostname' => 'acme-app-feat-stale.yak.example.com',
    ]);

    $task = YakTask::factory()->create([
        'repo' => 'acme/app',
        'branch_name' => 'feat/stale',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertDontSeeHtml('data-testid="preview-button"')
        ->assertDontSee('acme-app-feat-stale');
});
