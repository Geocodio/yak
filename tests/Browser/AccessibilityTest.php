<?php

use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;

test('task list page has no accessibility issues', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->success()->create();
    YakTask::factory()->running()->create();
    YakTask::factory()->failed()->create();

    $page = visit(route('tasks'));

    $page->assertNoAccessibilityIssues();
});

test('task detail page has no accessibility issues', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->success()->create([
        'description' => 'Accessibility test task',
        'result_summary' => 'Task completed successfully',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertNoAccessibilityIssues();
});

test('task list page has no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->count(3)->create();

    $page = visit(route('tasks'));

    $page->assertNoJavaScriptErrors();
});

test('task detail page has no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->success()->create();

    $page = visit(route('tasks.show', $task));

    $page->assertNoJavaScriptErrors();
});

test('dashboard pages have no javascript errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    YakTask::factory()->success()->create();

    $pages = visit([
        route('tasks'),
        route('costs'),
        route('repos'),
        route('health'),
    ]);

    $pages->assertNoJavaScriptErrors();
});

test('risk profile page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());

    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());

    $page = visit(route('repos.risk-profile.show', $repository));

    $page->assertVisible('[data-testid="risk-map-canvas"]')->assertNoAccessibilityIssues();
});

test('profile page with the GitHub card has no accessibility issues', function () {
    $this->actingAs(User::factory()->withGitHub(4242, 'mhansen')->create());

    visit(route('profile.edit'))->assertSee('GitHub account')->assertNoAccessibilityIssues(0);
});

test('held task page has no accessibility issues', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);
    $task = YakTask::factory()->awaitingCoOwner()->create(['description' => 'Add security headers']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$viewer->id]]);

    visit(route('tasks.show', $task))->assertVisible('[data-testid="co-owner-panel"]')->assertNoAccessibilityIssues(0);
});

test('repository page with the gate status has no accessibility issues', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $owner = User::factory()->withGitHub(1, 'mathias')->create();
    $this->mock(GitHubWriteAccess::class, function ($mock) {
        $mock->shouldReceive('check')->andReturn('write');
        $mock->shouldReceive('hasWriteAccess')->andReturn(true);
    });
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n", 'risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub([], "* @mathias\n");
    $this->actingAs($owner);

    visit(route('repos.edit', $repository))->assertVisible('[data-testid="co-owner-gate-status"]')->assertNoAccessibilityIssues(0);
});
