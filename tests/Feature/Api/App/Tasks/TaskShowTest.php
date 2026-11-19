<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\ContractFixture;

const TASK_SHOW_VOLATILE = ['coOwner.id'];

function taskShowSetup(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['app.url' => 'https://yak.example.com']);
    URL::forceRootUrl('https://yak.example.com');
    URL::forceScheme('https');
    Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function taskShowTask(array $attributes = []): YakTask
{
    return YakTask::factory()->create($attributes + [
        'public_id' => 'k3m9p2qa', 'repo' => 'example/api', 'mode' => TaskMode::Fix, 'description' => 'Upgrade the payments SDK',
        'source' => 'linear', 'external_id' => 'ENG-12', 'external_url' => 'https://linear.example.com/issue/ENG-12',
        'author_name' => 'Test person', 'responsible_name' => null, 'cost_usd' => 0, 'branch_name' => 'yak/upgrade-payments-sdk',
        'pr_url' => 'https://github.com/example/api/pull/41', 'pr_number' => 41, 'pr_merged_at' => null, 'pr_closed_at' => null,
        'result_summary' => 'Yak moved the SDK to the new major version.', 'thread_id' => null,
    ]);
}

it('shows a held task inside a thread to a person who can decide', function () {
    taskShowSetup();
    $user = User::factory()->withGitHub(login: 'test-person')->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['public_id' => 'd0e515ef', 'created_by_user_id' => $user->id, 'title' => 'Retry failed uploads']);
    $task = taskShowTask(['status' => TaskStatus::AwaitingCoOwner, 'thread_id' => $thread->id, 'started_by_user_id' => $user->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->id, 'repository_id' => Repository::where('slug', 'example/api')->value('id'), 'asked_user_ids' => [$user->id]]);
    $this->mock(GitHubWriteAccess::class)->shouldReceive('check')->andReturn('write');

    $response = $this->getJson(appTaskPath($task), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('threadId', 'd0e515ef')->assertJsonPath('canDrive', true)
        ->assertJsonPath('coOwner.status', 'pending')->assertJsonPath('coOwner.viewer.access', 'write');

    foreach (['runs', 'attempts', 'activitySummary', 'activity', 'debug', 'transcriptLogId'] as $key) {
        expect($response->json())->not->toHaveKey($key);
    }
    expect($response->json('coOwner.connectUrl'))->toStartWith('https://yak.example.com/');
    ContractFixture::assertMatches('app', 'task', $response->json(), TASK_SHOW_VOLATILE);
});

it('shows a task outside any thread with open questions', function () {
    taskShowSetup();
    $user = User::factory()->create(['name' => 'Test person']);
    $task = YakTask::factory()->withClarificationQuestions([[
        'id' => 'on_failure', 'header' => 'On failure', 'question' => 'When all retries fail, what should happen to the row?',
        'options' => [['label' => 'Mark the row as failed', 'description' => ''], ['label' => 'Fail the whole batch', 'description' => '']], 'multi_select' => false,
    ]])->create([
        'public_id' => 'k3m9p2qa', 'repo' => 'example/api', 'mode' => TaskMode::Fix, 'description' => 'Upgrade the payments SDK',
        'source' => 'linear', 'external_id' => 'ENG-12', 'external_url' => 'https://linear.example.com/issue/ENG-12',
        'author_name' => 'Test person', 'cost_usd' => 0, 'branch_name' => null, 'pr_url' => null, 'pr_number' => null,
        'session_id' => 'session-1', 'thread_id' => null, 'started_by_user_id' => $user->id,
    ]);

    $response = $this->getJson(appTaskPath($task), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('threadId', null)->assertJsonPath('canDrive', true);
    expect($response->json('questions'))->not->toBeEmpty();
    ContractFixture::assertMatches('app', 'task-question', $response->json(), TASK_SHOW_VOLATILE);
});

it('shows a private thread task read only to a share recipient', function () {
    taskShowSetup();
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner, ['public_id' => 'd0e515ef', 'title' => 'Retry failed uploads']);
    $task = taskShowTask(['status' => TaskStatus::Success, 'thread_id' => $thread->id, 'started_by_user_id' => $owner->id]);
    $recipient = shareThreadWith($thread, $owner);

    $response = $this->getJson(appTaskPath($task), phoneHeadersFor($recipient))->assertOk()
        ->assertJsonPath('canDrive', false)->assertJsonPath('threadId', 'd0e515ef');
    ContractFixture::assertMatches('app', 'task-read-only', $response->json(), TASK_SHOW_VOLATILE);
});

it('answers a child task id with its root', function () {
    taskShowSetup();
    $user = User::factory()->create();
    $root = taskShowTask(['status' => TaskStatus::Success, 'started_by_user_id' => $user->id]);
    $child = taskShowTask(['public_id' => 'f0110w01', 'status' => TaskStatus::Success, 'parent_task_id' => $root->id, 'started_by_user_id' => $user->id]);

    $this->getJson(appTaskPath($child), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('task.id', 'k3m9p2qa')->assertHeaderMissing('Location');
});

it('answers a hidden task exactly like a missing one', function () {
    taskShowSetup();
    $owner = User::factory()->create();
    $thread = privateThreadOf($owner);
    $task = taskShowTask(['thread_id' => $thread->id, 'started_by_user_id' => $owner->id]);
    $headers = phoneHeadersFor(User::factory()->create());

    $hidden = $this->getJson(appTaskPath($task), $headers)->assertNotFound()->getContent();
    expect($this->getJson('/api/app/v1/tasks/zzzzzzzz', $headers)->assertNotFound()->getContent())->toBe($hidden)
        ->and($this->getJson('/api/app/v1/tasks/' . $task->id, $headers)->assertNotFound()->getContent())->toBe($hidden);
});

it('builds without the activity log', function () {
    taskShowSetup();
    $user = User::factory()->create();
    $task = taskShowTask(['status' => TaskStatus::Success, 'started_by_user_id' => $user->id]);
    $headers = phoneHeadersFor($user);

    DB::enableQueryLog();
    $this->getJson(appTaskPath($task), $headers)->assertOk();
    $activityQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'task_logs')
        && preg_match('/metadata|limit|aggregate|max\(/', $sql) === 1);
    DB::disableQueryLog();

    expect($activityQueries->all())->toBe([]);
});

it('answers within the time bound when GitHub is slow', function () {
    taskShowSetup();
    configureFakeGitHubApp();
    $user = User::factory()->withGitHub()->create(['name' => 'Test person']);
    $task = taskShowTask(['status' => TaskStatus::AwaitingCoOwner, 'started_by_user_id' => $user->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->id, 'repository_id' => Repository::where('slug', 'example/api')->value('id'), 'asked_user_ids' => [$user->id]]);
    Cache::flush();

    $recorded = [];
    Http::swap(new Factory);
    Http::fake([
        'api.github.com/user/*' => function (Request $request, array $options) use (&$recorded) {
            $recorded[] = $options;
            throw new ConnectionException('Operation timed out');
        },
        'api.github.com/*' => function (Request $request, array $options) use (&$recorded) {
            $recorded[] = $options;
            throw new ConnectionException('Operation timed out');
        },
    ] + gitHubTokenFake());

    $headers = phoneHeadersFor($user);
    $this->getJson(appTaskPath($task), $headers)->assertOk()->assertJsonPath('coOwner.viewer.access', 'error');

    expect($recorded)->not->toBeEmpty();
    foreach ($recorded as $options) {
        expect($options['timeout'])->toBe(3)->and($options['connect_timeout'])->toBe(2);
    }

    $count = count($recorded);
    $this->getJson(appTaskPath($task), $headers)->assertOk()->assertJsonPath('coOwner.viewer.access', 'error');
    expect($recorded)->toHaveCount($count);
});

it('bounds the collaborator permission call when only that call is slow', function () {
    taskShowSetup();
    configureFakeGitHubApp();
    $user = User::factory()->withGitHub(login: 'test-person')->create(['name' => 'Test person']);
    $task = taskShowTask(['status' => TaskStatus::AwaitingCoOwner, 'started_by_user_id' => $user->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->id, 'repository_id' => Repository::where('slug', 'example/api')->value('id'), 'asked_user_ids' => [$user->id]]);
    Cache::flush();

    $permissionOptions = null;
    Http::swap(new Factory);
    Http::fake([
        'api.github.com/user/*' => Http::response(['login' => 'test-person']),
        'api.github.com/repos/*/collaborators/*/permission' => function (Request $request, array $options) use (&$permissionOptions) {
            $permissionOptions = $options;
            throw new ConnectionException('Operation timed out');
        },
    ] + gitHubTokenFake());

    $this->getJson(appTaskPath($task), phoneHeadersFor($user))->assertOk()->assertJsonPath('coOwner.viewer.access', 'error');

    expect($permissionOptions)->not->toBeNull()
        ->and($permissionOptions['timeout'])->toBe(3)->and($permissionOptions['connect_timeout'])->toBe(2);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    taskShowSetup();
    $user = User::factory()->create();
    $task = taskShowTask(['started_by_user_id' => $user->id]);

    $this->getJson(appTaskPath($task), ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson(appTaskPath($task), $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson(appTaskPath($task), $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
