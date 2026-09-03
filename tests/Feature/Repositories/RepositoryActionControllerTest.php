<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskMode;
use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.channels.github.app_bot_login', 'yak-bot[bot]');
    $this->actingAs(User::factory()->create());
});

test('toggle active deactivates an active repository', function () {
    $repo = Repository::factory()->create(['is_active' => true]);

    $this->post(route('repos.toggle-active', $repo))->assertRedirect();

    expect($repo->refresh()->is_active)->toBeFalse();
});

test('toggle active activates an inactive repository', function () {
    $repo = Repository::factory()->inactive()->create();

    $this->post(route('repos.toggle-active', $repo))->assertRedirect();

    expect($repo->refresh()->is_active)->toBeTrue();
});

test('rerun setup dispatches a new setup task', function () {
    Queue::fake();
    $repo = Repository::factory()->create(['slug' => 'rerun-test']);

    $this->post(route('repos.rerun-setup', $repo))->assertRedirect();

    expect($repo->refresh()->setup_task_id)->not->toBeNull();
    expect(YakTask::where('repo', 'rerun-test')->where('mode', TaskMode::Setup)->exists())->toBeTrue();
});

test('rerun setup redirects to the new task', function () {
    Queue::fake();
    $repo = Repository::factory()->create(['slug' => 'redirect-test']);

    $this->post(route('repos.rerun-setup', $repo))
        ->assertRedirect(route('tasks.show', YakTask::where('repo', 'redirect-test')->first()));
});

test('review open prs enqueues retroactive review tasks', function () {
    Bus::fake();
    $repo = Repository::factory()->create(['slug' => 'geocodio/api']);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('appBotLogin')->andReturn('yak-bot[bot]');
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 1, 'html_url' => 'u1', 'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'maria'], 'head' => ['ref' => 'h', 'sha' => 's1'], 'base' => ['ref' => 'main', 'sha' => 'b1']],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $this->post(route('repos.review-open-prs', $repo))->assertRedirect();

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

test('rebuild deployments dispatches the bulk rebuild job', function () {
    Bus::fake();
    $repo = Repository::factory()->create();

    $this->post(route('repos.rebuild-deployments', $repo))->assertRedirect();

    Bus::assertDispatched(
        RebuildRepositoryDeploymentsJob::class,
        fn (RebuildRepositoryDeploymentsJob $job): bool => $job->repositoryId === $repo->id,
    );
});
