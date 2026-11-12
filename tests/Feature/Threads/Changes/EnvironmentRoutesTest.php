<?php

use App\Enums\DeploymentStatus;
use App\Enums\ThreadSandboxState;
use App\Models\BranchDeployment;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;

it('reports stoppedAfterMinutes from config', function () {
    config(['yak.threads.pause_to_stop_minutes' => 17]);
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))
        ->assertOk()
        ->assertJsonPath('stoppedAfterMinutes', 17);
});

it('shows pause state, branch, PR number, deployment, account name and model', function () {
    $viewer = User::factory()->create();
    $creator = User::factory()->create(['name' => 'Ada Lovelace']);
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $viewer->id, 'name' => 'Work token']);
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);
    $thread = Thread::factory()->paused()->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/fix-cache',
        'model' => 'opus',
        'claude_account_id' => $account->id,
        'created_by_user_id' => $creator->id,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'pr_url' => 'https://github.com/acme/widgets/pull/42', 'pr_number' => null]);
    BranchDeployment::factory()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/fix-cache',
        'hostname' => 'widgets-fix.yak.example.com',
        'status' => DeploymentStatus::Running,
    ]);

    $this->actingAs($viewer)->getJson(route('threads.environment.show', $thread))
        ->assertOk()
        ->assertJsonPath('sandbox.state', ThreadSandboxState::Paused->value)
        ->assertJsonPath('branch', 'yak/fix-cache')
        ->assertJsonPath('pullRequest.number', 42)
        ->assertJsonPath('pullRequest.url', 'https://github.com/acme/widgets/pull/42')
        ->assertJsonPath('pullRequest.state', 'open')
        ->assertJsonPath('deployment.url', 'https://widgets-fix.yak.example.com')
        ->assertJsonPath('deployment.status', 'running')
        ->assertJsonPath('account.name', 'Work token')
        ->assertJsonPath('account.kind', 'setup_token')
        ->assertJsonPath('account.isYours', true)
        ->assertJsonPath('model', 'opus')
        ->assertJsonPath('startedBy.name', 'Ada Lovelace')
        ->assertJsonPath('repositories.0.repo', 'acme/widgets')
        ->assertJsonPath('repositories.0.role', 'primary');
});

it('does not mark another person\'s account as yours', function () {
    $thread = Thread::factory()->create(['claude_account_id' => ClaudeAccount::factory()->create()->id]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))
        ->assertJsonPath('account.isYours', false);
});

it('never includes the account secret', function () {
    $account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-oat01-SUPERSECRETVALUE']);
    $thread = Thread::factory()->create(['claude_account_id' => $account->id]);

    $response = $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))->assertOk();

    expect($response->getContent())->not->toContain('SUPERSECRETVALUE')
        ->and($response->getContent())->not->toContain('secret');
});

it('returns nulls for a thread with no task or deployment', function () {
    $thread = Thread::factory()->create(['branch' => null, 'model' => null]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))
        ->assertOk()
        ->assertJsonPath('pullRequest', null)
        ->assertJsonPath('deployment', null)
        ->assertJsonPath('account', null)
        ->assertJsonPath('model', null)
        ->assertJsonPath('startedBy', null)
        ->assertJsonPath('branch', null)
        ->assertJsonPath('repositories', []);
});

it('describes each repository with path, setup error and role actions', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/app']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);
    ThreadRepository::factory()->changes()->failed('npm ci failed')->create(['thread_id' => $thread->id, 'repo' => 'acme/web', 'sandbox_path' => '/extras/web', 'added_by' => 'claude']);

    $response = $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))->assertOk();

    $response->assertJsonPath('repositories.0.isDatasetAttached', true)
        ->assertJsonPath('repositories.0.canMakeContextOnly', false)
        ->assertJsonPath('repositories.1.path', '/extras/web')
        ->assertJsonPath('repositories.1.setupStatus', 'failed')
        ->assertJsonPath('repositories.1.setupError', 'npm ci failed')
        ->assertJsonPath('repositories.1.isDatasetAttached', false)
        ->assertJsonPath('repositories.1.canMakeContextOnly', true)
        ->assertJsonPath('repositories.1.addedBy', 'claude');
});

it('does not offer context only while the repo has an open pull request', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'pr_url' => 'https://github.com/acme/web/pull/9']);
    ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/web', 'pull_request_url' => $task->pr_url]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))
        ->assertJsonPath('repositories.0.canMakeContextOnly', false);
});

it('lists the directories the latest teleport did not upload', function () {
    $thread = Thread::factory()->create();
    Teleport::factory()->create(['thread_id' => $thread->id, 'payload' => ['missing_directories' => ['/Users/ada/shared', '/Users/ada/data']]]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.environment.show', $thread))
        ->assertJsonPath('notUploadedDirectories', ['/Users/ada/shared', '/Users/ada/data']);
});
