<?php

use App\Livewire\Tasks\PreviewWidget;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders a preview card when the task branch has an active deployment', function () {
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

    Livewire::test(PreviewWidget::class, ['task' => $task])
        ->assertSee('acme-app-feat-foo.yak.example.com');
});

it('renders nothing when the task has no branch', function () {
    $task = YakTask::factory()->create(['branch_name' => null]);

    Livewire::test(PreviewWidget::class, ['task' => $task])
        ->assertDontSee('Preview');
});

it('renders nothing when no matching deployment exists', function () {
    Repository::factory()->create(['slug' => 'acme/app']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/app',
        'branch_name' => 'feat/nothing-deployed',
    ]);

    Livewire::test(PreviewWidget::class, ['task' => $task])
        ->assertDontSee('Preview');
});

it('skips destroyed deployments', function () {
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

    Livewire::test(PreviewWidget::class, ['task' => $task])
        ->assertDontSee('acme-app-feat-stale');
});
