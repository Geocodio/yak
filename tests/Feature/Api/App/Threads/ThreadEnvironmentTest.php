<?php

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Tests\Support\ContractFixture;

function environmentThread(User $owner, array $attributes = []): Thread
{
    return privateThreadOf($owner, $attributes + ['title' => 'Move search to the new index', 'name' => 'move-search', 'repo' => 'example/api', 'created_by_user_id' => $owner->id]);
}

it('shows the environment of a running thread', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $owner = User::factory()->create(['name' => 'Test person']);
    $account = ClaudeAccount::factory()->create(['name' => 'Team account', 'owner_user_id' => User::factory()->create()->id]);
    $thread = environmentThread($owner, [
        'branch' => 'yak/move-search',
        'model' => 'opus',
        'claude_account_id' => $account->id,
        'sandbox_state' => ThreadSandboxState::Running,
        'sandbox_state_changed_at' => '2026-11-15 11:00:00',
    ]);
    YakTask::factory()->create(['thread_id' => $thread->id, 'pr_url' => 'https://github.com/example/api/pull/2301', 'started_by_user_id' => $owner->id]);
    config(['yak.threads.pause_to_stop_minutes' => 30]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api', 'role' => RepositoryRole::Primary, 'sandbox_path' => '/workspace']);
    ThreadRepository::factory()->changes()->create([
        'thread_id' => $thread->id,
        'repo' => 'example/web',
        'sandbox_path' => '/workspace/example-web',
        'setup_status' => RepositorySetupStatus::Failed,
        'setup_error' => 'npm install exited with 1.',
        'setup_output' => 'npm ERR! missing script: build',
        'added_by' => 'Test person',
    ]);

    $response = $this->getJson(appThreadPath($thread, '/environment'), phoneHeadersFor($owner))->assertOk()
        ->assertJsonPath('sandbox.state', 'running')
        ->assertJsonPath('pullRequest.number', 2301)
        ->assertJsonPath('deployment', null)
        ->assertJsonPath('account.isYours', false)
        ->assertJsonPath('stoppedAfterMinutes', 30)
        ->assertJsonPath('repositories.1.setupStatus', 'failed')
        ->assertJsonPath('repositories.1.canMakeContextOnly', true);

    ContractFixture::assertMatches('app', 'thread-environment', $response->json(), ['repositories.*.id']);
});

it('never shows a secret from a setup log', function () {
    $owner = User::factory()->create();
    $thread = environmentThread($owner, ['sandbox_state' => ThreadSandboxState::Running, 'branch' => 'yak/thread-branch']);
    $row = ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'example/web', 'setup_status' => RepositorySetupStatus::Pending]);
    Repository::factory()->create(['slug' => 'example/web', 'build_credentials' => ['NPM_TOKEN' => 'secret-value']]);

    foreach ([
        "@@YAK_BEGIN 0\ncloning from https://x-token:abc123@github.com/example/web.git\n@@YAK_EXIT 0 0 3\n",
        "@@YAK_INSPECT 0\n@@YAK_SETUP\n@@YAK_HEAD\naaa\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n",
        "@@YAK_BEGIN 0\nusing secret-value to log in\n@@YAK_EXIT 0 1 1\n",
    ] as $output) {
        app(SandboxManager::class)->queueExecResult(Process::result($output));
    }
    app(ExtraRepositorySetup::class)->setUp($thread, $row);

    $body = $this->getJson(appThreadPath($thread, '/environment'), phoneHeadersFor($owner))->assertOk()->getContent();

    expect($body)->not->toContain('secret-value')->not->toContain('abc123')->toContain('[redacted]');
});

it('shows a thread with no sandbox, account or repositories', function () {
    $owner = User::factory()->create();
    $thread = environmentThread($owner);

    $this->getJson(appThreadPath($thread, '/environment'), phoneHeadersFor($owner))->assertOk()
        ->assertJsonPath('sandbox.state', 'none')
        ->assertJsonPath('account', null)
        ->assertJsonPath('pullRequest', null)
        ->assertJsonPath('repositories', []);
});

it('lets a share recipient read it', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id]);
    $thread = environmentThread($owner, ['claude_account_id' => $account->id]);
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->getJson(appThreadPath($thread, '/environment'), phoneHeadersFor($recipient))->assertOk()->assertJsonPath('account.isYours', false);
});

it('answers a hidden thread like a missing one', function () {
    $thread = environmentThread(User::factory()->create());
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->getJson('/api/app/v1/threads/zzzzzzzz/environment', $headers)->assertNotFound();
    $this->getJson(appThreadPath($thread, '/environment'), $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $owner = User::factory()->create();
    $path = appThreadPath(environmentThread($owner), '/environment');

    $this->getJson($path, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $owner->id], isPhone: false);
    $this->getJson($path, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
