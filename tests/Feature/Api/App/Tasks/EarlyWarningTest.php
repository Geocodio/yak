<?php

use App\Enums\TaskStatus;
use App\Http\Resources\EarlyWarningData;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ContractFixture;

function earlyWarningPath(YakTask $task, string $suffix): string
{
    return appTaskPath($task, "/co-owner/{$suffix}");
}

function lockWaitTimeout(): QueryException
{
    return tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']);
}

function earlyWarningGitHubFakes(array $overrides = []): void
{
    Http::swap(new Factory);
    Http::fake($overrides + gitHubTokenFake() + [
        'api.github.com/user/4242' => Http::response(['login' => 'test-person']),
        'api.github.com/user/777' => Http::response(['login' => 'second-person']),
        'api.github.com/user/888' => Http::response(['login' => 'third-person']),
        'api.github.com/repos/example/api/collaborators/*/permission' => Http::response(['role_name' => 'write']),
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    configureFakeGitHubApp();
    config()->set('app.url', 'https://yak.example.com');
    $this->repository = Repository::factory()->create(['slug' => 'example-api', 'github_full_name' => 'example/api']);
    $this->user = User::factory()->withGitHub()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user);
    $this->second = User::factory()->withGitHub(777, 'second-person')->create(['name' => 'Second person']);
    $this->task = YakTask::factory()->create([
        'public_id' => 'k3m9p2qa', 'repo' => 'example-api', 'source' => 'slack', 'description' => 'Retry failed uploads',
        'started_by_user_id' => $this->user->id, 'thread_id' => null, 'status' => TaskStatus::Running,
        'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown'],
    ]);
    earlyWarningGitHubFakes();
});

it('lists the people who can co-own', function () {
    User::factory()->withGitHub(888, 'third-person')->create(['name' => 'Third person']);
    User::factory()->create(['name' => 'Unlinked person']);

    $response = $this->getJson(earlyWarningPath($this->task, 'candidates'), $this->headers)->assertOk()
        ->assertJsonPath('isComplete', true)->assertJsonCount(2, 'candidates')
        ->assertJsonPath('candidates.0.name', 'Second person')->assertJsonPath('candidates.1.name', 'Third person');

    ContractFixture::assertMatches('app', 'task-co-owner-candidates', $response->json(), ['candidates.*.id']);
});

it('stops checking after eight seconds', function () {
    foreach (range(1, 5) as $number) {
        User::factory()->withGitHub(900 + $number, "person-{$number}")->create(['name' => "Person {$number}"]);
    }
    $checks = 0;
    $this->app->instance(GitHubWriteAccess::class, new class($checks) extends GitHubWriteAccess
    {
        public function __construct(private int &$checks) {}

        public function check(Repository $repository, User $user, bool $isTimeBounded = false): string
        {
            $this->checks++;
            test()->travel(3)->seconds();

            return 'write';
        }
    });

    $this->getJson(earlyWarningPath($this->task, 'candidates'), $this->headers)->assertOk()
        ->assertJsonPath('isComplete', false)->assertJsonCount(3, 'candidates');

    expect($checks)->toBe(3);
});

it('passes the time bound to GitHub', function () {
    $recorded = [];
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/*' => function (HttpRequest $request, array $options) use (&$recorded) {
            $recorded[] = $options;

            return str_contains($request->url(), '/permission') ? Http::response(['role_name' => 'write']) : Http::response(['login' => 'second-person']);
        },
    ]);

    $this->getJson(earlyWarningPath($this->task, 'candidates'), $this->headers)->assertOk();
    $this->postJson(earlyWarningPath($this->task, 'tag'), ['user_id' => $this->second->id], $this->headers)->assertOk();

    expect($recorded)->not->toBeEmpty();
    foreach ($recorded as $options) {
        expect($options['timeout'])->toBe(3)->and($options['connect_timeout'])->toBe(2);
    }
});

it('waits at most five seconds for the task lock', function (string $action) {
    $body = $action === 'tag' ? ['user_id' => $this->second->id] : [];
    YakTask::updating(fn () => throw lockWaitTimeout());

    $this->postJson(earlyWarningPath($this->task, $action), $body, $this->headers)->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is still handling another request for this task. Try again in a moment.');

    expect($this->task->fresh()->early_warning['state'])->toBe('shown');
})->with(['tag', 'decline']);

it('answers an empty list when the viewer may not pick', function () {
    $this->getJson(earlyWarningPath($this->task, 'candidates'), phoneHeadersFor($this->second))->assertOk()
        ->assertExactJson(['candidates' => [], 'isComplete' => true]);
});

it('answers an empty list when the early warning is already answered', function () {
    $this->task->update(['early_warning' => ['areas' => [], 'state' => 'declined']]);

    $this->getJson(earlyWarningPath($this->task, 'candidates'), $this->headers)->assertOk()
        ->assertExactJson(['candidates' => [], 'isComplete' => true]);
});

it('tags a co-owner and declines', function () {
    $tag = $this->postJson(earlyWarningPath($this->task, 'tag'), ['user_id' => $this->second->id], $this->headers)->assertOk()->assertExactJson(['status' => 'tagged']);
    $repeat = $this->postJson(earlyWarningPath($this->task, 'tag'), ['user_id' => $this->second->id], $this->headers)->assertOk()->assertExactJson(['status' => 'tagged']);

    expect($this->task->fresh()->tagged_co_owner_user_id)->toBe($this->second->id)
        ->and($this->task->fresh()->early_warning['state'])->toBe('tagged');
    ContractFixture::assertMatches('app', 'task-co-owner-tag', $tag->json());
});

it('declines and answers a repeat the same', function () {
    $decline = $this->postJson(earlyWarningPath($this->task, 'decline'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'declined']);
    $this->postJson(earlyWarningPath($this->task, 'decline'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'declined']);

    expect($this->task->fresh()->early_warning['state'])->toBe('declined');
    ContractFixture::assertMatches('app', 'task-co-owner-decline', $decline->json());
});

it('refuses the other answer after the first', function () {
    $this->postJson(earlyWarningPath($this->task, 'decline'), [], $this->headers)->assertOk();

    $this->postJson(earlyWarningPath($this->task, 'tag'), ['user_id' => $this->second->id], $this->headers)->assertStatus(409)->assertJsonPath('error', 'already_decided');
    expect($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('maps every refusal to its code', function (string $case, int $status, string $code) {
    $action = 'tag';
    $body = ['user_id' => $this->second->id];

    match ($case) {
        'not requester' => $this->task->update(['started_by_user_id' => User::factory()->create()->id]),
        'not linked' => $this->second->update(['github_id' => null, 'github_login' => null, 'github_linked_at' => null]),
        'read access' => earlyWarningGitHubFakes(['api.github.com/repos/example/api/collaborators/*/permission' => Http::response(['role_name' => 'read'])]),
        'access check error' => earlyWarningGitHubFakes(['api.github.com/user/777' => fn () => throw new ConnectionException('Operation timed out')]),
        'already decided' => CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id]),
    };
    Cache::flush();

    $response = $this->postJson(earlyWarningPath($this->task, $action), $body, $this->headers)->assertStatus($status)->assertJsonPath('error', $code);

    expect($response->json('message'))->not->toBeEmpty()
        ->and($this->task->fresh()->early_warning['state'])->toBe('shown');
})->with([
    'not requester' => ['not requester', 403, 'not_requester'],
    'not linked' => ['not linked', 409, 'not_linked'],
    'read access' => ['read access', 409, 'no_write_access'],
    'access check error' => ['access check error', 503, 'access_check_failed'],
    'already decided' => ['already decided', 409, 'already_decided'],
]);

it('refuses a decline from someone who is not the requester', function () {
    $this->postJson(earlyWarningPath($this->task, 'decline'), [], phoneHeadersFor($this->second))->assertForbidden();
    $this->task->update(['started_by_user_id' => $this->second->id]);
    $this->postJson(earlyWarningPath($this->task, 'decline'), [], $this->headers)->assertStatus(403)->assertJsonPath('error', 'not_requester');

    expect($this->task->fresh()->early_warning['state'])->toBe('shown');
});

it('validates the user id', function (mixed $userId) {
    $response = $this->postJson(earlyWarningPath($this->task, 'tag'), ['user_id' => $userId], $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    expect($response->json('details.errors'))->toHaveKey('user_id');
})->with(['missing' => [null], 'unknown' => [999999], 'not a number' => ['x']]);

describe('who may answer', function () {
    beforeEach(function () {
        $this->thread = privateThreadOf($this->user, ['public_id' => 'd0e515ef']);
        $this->task->update(['thread_id' => $this->thread->id]);
        $this->routes = [
            ['POST', 'tag', ['user_id' => 1]],
            ['POST', 'decline', []],
            ['GET', 'candidates', []],
        ];
    });

    it('refuses a share recipient on tag and decline but lets them read candidates', function () {
        $headers = phoneHeadersFor(shareThreadWith($this->thread, $this->user));

        $this->postJson(earlyWarningPath($this->task, 'tag'), ['user_id' => $this->second->id], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->postJson(earlyWarningPath($this->task, 'decline'), [], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->getJson(earlyWarningPath($this->task, 'candidates'), $headers)->assertOk()->assertExactJson(['candidates' => [], 'isComplete' => true]);

        expect($this->task->fresh()->early_warning['state'])->toBe('shown');
    });

    it('answers a hidden task exactly like a missing one', function () {
        $stranger = phoneHeadersFor(User::factory()->create());
        $missing = (clone $this->task)->forceFill(['public_id' => 'nope0000']);

        foreach ($this->routes as [$method, $route, $body]) {
            $hidden = $this->json($method, earlyWarningPath($this->task, $route), $body, $stranger)->assertNotFound();
            $unknown = $this->json($method, earlyWarningPath($missing, $route), $body, $stranger)->assertNotFound();
            expect($hidden->json())->toBe($unknown->json());
            $numeric = $this->json($method, str_replace($this->task->public_id, (string) $this->task->id, earlyWarningPath($this->task, $route)), $body, $stranger)->assertNotFound();
            expect($numeric->json())->toBe($unknown->json());
        }
    });

    it('refuses without a token, with a revoked phone and with a computer token', function () {
        foreach ($this->routes as [$method, $route, $body]) {
            $this->json($method, earlyWarningPath($this->task, $route), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        }

        [$device, $headers] = phoneWithHeaders(['user_id' => $this->user->id]);
        $device->update(['revoked_at' => now()]);
        $this->getJson(earlyWarningPath($this->task, 'candidates'), $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

        [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->user->id], isPhone: false);
        $this->getJson(earlyWarningPath($this->task, 'candidates'), $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    });
});

it('keeps the web candidate list unbounded and unchanged', function () {
    expect(EarlyWarningData::candidates($this->task, $this->user))->toBeArray();
});
