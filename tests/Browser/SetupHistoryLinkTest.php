<?php

use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;

test('a setup history row opens its run', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $repository = Repository::factory()->create();
    $task = YakTask::factory()->failed()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup, 'external_id' => 'setup-row-link']);

    visit(route('repos.settings.show', ['repository' => $repository, 'section' => 'sandbox']))
        ->click('[data-testid="setup-history-row-setup-row-link"] td:nth-child(3)')
        ->assertPathIs('/repos/' . $repository->slug . '/runs/' . $task->public_id)
        ->assertNoJavaScriptErrors();
});
