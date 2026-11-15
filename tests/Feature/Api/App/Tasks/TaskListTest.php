<?php

use App\DataTransferObjects\TaskListFilters;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\TaskListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\Support\ContractFixture;

/**
 * Four root tasks, newest first: the SDK upgrade (held for a co-owner, PR 41 open), the upload retry (with a follow-up),
 * the endpoint rename and an older README task.
 */
function taskListWorld(): User
{
    test()->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['app.url' => 'https://yak.example.com']);
    URL::forceRootUrl('https://yak.example.com');
    URL::forceScheme('https');
    Repository::factory()->create(['slug' => 'example/api']);
    $user = User::factory()->create(['name' => 'Test person']);
    $base = [
        'repo' => 'example/api',
        'mode' => TaskMode::Fix,
        'author_name' => 'Test person',
        'responsible_name' => null,
        'cost_usd' => 0,
        'thread_id' => null,
        'started_by_user_id' => $user->id,
        'branch_name' => null,
        'pr_url' => null,
        'pr_merged_at' => null,
        'pr_closed_at' => null,
        'pr_number' => null,
        'external_url' => null,
    ];

    $sdk = YakTask::factory()->create(array_merge($base, [
        'public_id' => 'k3m9p2qa', 'description' => 'Upgrade the payments SDK', 'source' => 'linear', 'external_id' => 'ENG-12',
        'external_url' => 'https://linear.example.com/issue/ENG-12', 'status' => TaskStatus::AwaitingCoOwner,
        'pr_url' => 'https://github.com/example/api/pull/41', 'pr_number' => 41, 'created_at' => now()->subHour(),
    ]));
    CoOwnerRequest::factory()->create(['task_id' => $sdk->id, 'root_task_id' => $sdk->id, 'asked_user_ids' => [$user->id]]);

    $retry = YakTask::factory()->create(array_merge($base, [
        'public_id' => 'a1b2c3d4', 'description' => 'Retry failed uploads', 'source' => 'dashboard', 'external_id' => 'dashboard-1',
        'status' => TaskStatus::Success, 'branch_name' => 'yak/retry-failed-uploads', 'created_at' => now()->subHours(2),
    ]));
    YakTask::factory()->create(array_merge($base, [
        'public_id' => 'f0110w01', 'description' => 'Handle the empty batch', 'source' => 'dashboard', 'external_id' => 'dashboard-1',
        'status' => TaskStatus::Success, 'parent_task_id' => $retry->id, 'branch_name' => 'yak/retry-failed-uploads', 'created_at' => now()->subHour(),
    ]));

    YakTask::factory()->create(array_merge($base, [
        'public_id' => 'r3n4m3ep', 'description' => 'Rename the upload endpoint', 'source' => 'slack', 'external_id' => 'dashboard-1',
        'status' => TaskStatus::Failed, 'created_at' => now()->subHours(3),
    ]));

    YakTask::factory()->create(array_merge($base, [
        'public_id' => 't1dy0ld1', 'description' => 'Tidy the README', 'source' => 'manual', 'external_id' => 'dashboard-1',
        'status' => TaskStatus::Success, 'created_at' => now()->subDays(2),
    ]));

    return $user;
}

it('lists the first page with counts and options', function () {
    $user = taskListWorld();
    app()->instance(TaskListQuery::class, new TaskListQuery(perPage: 3));

    $response = $this->getJson('/api/app/v1/tasks', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('nextPage', 2)
        ->assertJsonPath('tasks.0.id', 'k3m9p2qa')
        ->assertJsonPath('tasks.0.askedFirstName', 'Test')
        ->assertJsonPath('tasks.1.id', 'a1b2c3d4')
        ->assertJsonPath('tasks.2.id', 'r3n4m3ep')
        ->assertJsonPath('counts.needsCoOwner', 1);

    expect($response->json('tasks.1.followUps'))->toHaveCount(1);
    ContractFixture::assertMatches('app', 'tasks', $response->json());

    $this->getJson('/api/app/v1/tasks?page=2', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('tasks.0.id', 't1dy0ld1')
        ->assertJsonPath('nextPage', null);
    $this->getJson('/api/app/v1/tasks?page=9', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('tasks', [])->assertJsonPath('nextPage', null);
});

it('pages by 50', function () {
    $user = User::factory()->create();
    YakTask::factory()->count(51)->create(['thread_id' => null, 'started_by_user_id' => $user->id, 'mode' => TaskMode::Fix]);

    $this->getJson('/api/app/v1/tasks', phoneHeadersFor($user))->assertOk()
        ->assertJsonCount(50, 'tasks')->assertJsonPath('nextPage', 2);
    $this->getJson('/api/app/v1/tasks?page=2', phoneHeadersFor($user))->assertOk()
        ->assertJsonCount(1, 'tasks')->assertJsonPath('nextPage', null);
});

it('answers an empty tab', function () {
    $user = taskListWorld();

    $response = $this->getJson('/api/app/v1/tasks?tab=setup', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('tasks', [])->assertJsonPath('nextPage', null);
    ContractFixture::assertMatches('app', 'tasks-empty', $response->json());
});

it('filters like the web', function (string $query) {
    $user = taskListWorld();
    YakTask::factory()->create(['public_id' => 'w3bt4sk1', 'repo' => 'example/web', 'mode' => TaskMode::Fix, 'thread_id' => null, 'source' => 'slack', 'status' => TaskStatus::Failed, 'pr_url' => 'https://github.com/example/web/pull/3', 'created_at' => now()->subDay()]);
    parse_str($query, $parameters);

    $appIds = collect($this->getJson('/api/app/v1/tasks?' . $query, phoneHeadersFor($user))->assertOk()->json('tasks'))->pluck('id')->all();

    $filters = TaskListFilters::fromLenientRequest(Request::create('/tasks', 'GET', $parameters));
    $webIds = collect(app(TaskListQuery::class)->rows($filters, 1)->items())->pluck('id')->all();

    expect($appIds)->toBe($webIds)->not->toBeEmpty();
})->with([
    'failed' => 'status=failed',
    'held for a co-owner' => 'status=awaiting_co_owner',
    'slack' => 'source=slack',
    'web repository' => 'repo=example/web',
    'open pull request' => 'pr=open',
    'no pull request' => 'pr=none',
    'sorted by status' => 'sort=status&direction=asc',
]);

it('finds tasks by description and external id', function () {
    $user = taskListWorld();
    $headers = phoneHeadersFor($user);

    $this->getJson('/api/app/v1/tasks?search=payments', $headers)->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.id', 'k3m9p2qa');
    $this->getJson('/api/app/v1/tasks?search=ENG-12', $headers)->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.id', 'k3m9p2qa');
    $this->getJson('/api/app/v1/tasks?search=100%25_', $headers)->assertOk()->assertJsonPath('tasks', []);
    $this->getJson('/api/app/v1/tasks?search=%20%20', $headers)->assertOk()->assertJsonCount(4, 'tasks');
});

it('validates the query', function (string $query, string $field) {
    $user = User::factory()->create();

    $this->getJson('/api/app/v1/tasks?' . $query, phoneHeadersFor($user))->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    ['tab=all', 'tab'],
    ['status=done', 'status'],
    ['pr=maybe', 'pr'],
    ['sort=cost', 'sort'],
    ['direction=up', 'direction'],
    ['page=0', 'page'],
    ['page=1001', 'page'],
    ['page=abc', 'page'],
    ['search=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'search'],
]);

it('ignores unknown fields', function () {
    $user = taskListWorld();

    $this->getJson('/api/app/v1/tasks?colour=blue', phoneHeadersFor($user))->assertOk()->assertJsonCount(4, 'tasks');
});

it('never lists another person\'s private thread task', function () {
    $user = taskListWorld();
    $owner = User::factory()->create();
    $thread = privateThreadOf($owner);
    YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Secret plan', 'repo' => 'example/secret', 'source' => 'secretsource', 'mode' => TaskMode::Fix, 'started_by_user_id' => $owner->id]);

    $response = $this->getJson('/api/app/v1/tasks', phoneHeadersFor($user))->assertOk();

    expect(json_encode($response->json()))->not->toContain('Secret plan', 'example/secret', 'secretsource');
    expect($response->json('counts.tasks'))->toBe(4);
});

it('answers an empty list for a new user', function () {
    $user = User::factory()->create();

    $this->getJson('/api/app/v1/tasks', phoneHeadersFor($user))->assertOk()
        ->assertExactJson(['tasks' => [], 'nextPage' => null, 'counts' => ['tasks' => 0, 'reviews' => 0, 'setup' => 0, 'needsCoOwner' => 0], 'options' => ['repos' => [], 'sources' => []]]);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();

    $this->getJson('/api/app/v1/tasks', ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson('/api/app/v1/tasks', $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson('/api/app/v1/tasks', $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
