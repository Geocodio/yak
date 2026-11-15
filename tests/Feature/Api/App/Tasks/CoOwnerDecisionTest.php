<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\ContractFixture;

function coOwnerGitHubFakes(string $role = 'write', array $extra = []): void
{
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + $extra + [
        'api.github.com/user/4242' => Http::response(['login' => 'test-person']),
        'api.github.com/user/777' => Http::response(['login' => 'second-person']),
        'api.github.com/repos/example/api/collaborators/*/permission' => Http::response(['role_name' => $role]),
        'api.github.com/repos/example/api/pulls?*' => Http::response([]),
        'api.github.com/repos/example/api/pulls' => Http::response(['number' => 41, 'html_url' => 'https://github.com/example/api/pull/41']),
        'api.github.com/repos/example/api/issues/*/labels' => Http::response([]),
        'api.github.com/repos/example/api/pulls/*/requested_reviewers' => Http::response([]),
        'api.github.com/repos/example/api/git/refs/*' => Http::response([], 204),
    ]);
}

function decisionPath(YakTask $task, string $decision): string
{
    return appTaskPath($task, "/co-owner/{$decision}");
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    configureFakeGitHubApp();
    config()->set('yak.pr_title_writer.enabled', false);
    config()->set('app.url', 'https://yak.example.com');
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class, RunFollowUpJob::class]);
    $this->repository = Repository::factory()->create(['slug' => 'example-api', 'github_full_name' => 'example/api', 'default_branch' => 'main']);
    $this->user = User::factory()->withGitHub()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user);
    $this->task = YakTask::factory()->awaitingCoOwner()->create([
        'public_id' => 'k3m9p2qa', 'repo' => 'example-api', 'branch_name' => 'yak/retry-uploads', 'source' => 'slack',
        'description' => 'Retry failed uploads', 'started_by_user_id' => $this->user->id, 'thread_id' => null,
    ]);
    $this->coOwnerRequest = CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id]);
    coOwnerGitHubFakes();
});

it('approves a held change and answers a repeat the same', function () {
    $first = $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'approved']);
    $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'approved']);

    expect($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->task->fresh()->pr_url)->toBe('https://github.com/example/api/pull/41');
    expect(Http::recorded(fn (HttpRequest $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')))->toHaveCount(1);
    ContractFixture::assertMatches('app', 'task-co-owner-approve', $first->json());
});

it('requests changes, rejects and withdraws', function (string $decision, string $status, string $fixture, array $body) {
    $first = $this->postJson(decisionPath($this->task, $decision), $body, $this->headers)->assertOk()->assertExactJson(['status' => $status]);
    $this->postJson(decisionPath($this->task, $decision), $body, $this->headers)->assertOk()->assertExactJson(['status' => $status]);

    expect($this->coOwnerRequest->fresh()->status->value)->toBe($status);
    ContractFixture::assertMatches('app', $fixture, $first->json());
})->with([
    'request changes' => ['request-changes', 'changes_requested', 'task-co-owner-request-changes', ['feedback' => 'Please keep the old webhook path.']],
    'reject' => ['reject', 'rejected', 'task-co-owner-reject', []],
    'withdraw' => ['withdraw', 'withdrawn', 'task-co-owner-withdraw', []],
]);

it('creates one follow-up for a repeated request for changes', function () {
    $body = ['feedback' => 'Please keep the old webhook path.'];

    $this->postJson(decisionPath($this->task, 'request-changes'), $body, $this->headers)->assertOk();
    $this->postJson(decisionPath($this->task, 'request-changes'), $body, $this->headers)->assertOk();

    expect(YakTask::where('parent_task_id', $this->task->id)->count())->toBe(1);
});

it('refuses a request someone else decided', function () {
    $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertOk();
    $second = User::factory()->withGitHub(777, 'second-person')->create(['name' => 'Second person']);

    $response = $this->postJson(decisionPath($this->task, 'approve'), [], phoneHeadersFor($second))
        ->assertStatus(409)->assertJsonPath('error', 'already_resolved')->assertJsonPath('message', 'Someone already decided this request.');

    ContractFixture::assertMatches('app', 'error-already-resolved', $response->json());
});

it('refuses a different decision after the first', function () {
    $this->postJson(decisionPath($this->task, 'reject'), [], $this->headers)->assertOk();

    $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'already_resolved');
});

it('maps every refusal to its code and status', function (string $case, int $status, string $code) {
    $decision = 'approve';

    match ($case) {
        'not linked' => $this->user->update(['github_id' => null, 'github_login' => null, 'github_linked_at' => null]),
        'read access' => coOwnerGitHubFakes('read'),
        'not the requester' => (function () use (&$decision): void {
            $decision = 'withdraw';
            $this->task->update(['started_by_user_id' => User::factory()->create()->id]);
        })(),
        'access check error' => coOwnerGitHubFakes('write', ['api.github.com/user/4242' => Http::response([], 500)]),
        'github failure' => coOwnerGitHubFakes('write', ['api.github.com/repos/example/api/pulls' => Http::response(['message' => 'Validation Failed'], 422)]),
    };

    $response = $this->postJson(decisionPath($this->task, $decision), [], $this->headers)->assertStatus($status)->assertJsonPath('error', $code);

    expect($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
    match ($case) {
        'not linked' => expect($response->json('details.connectUrl'))->toBe(route('settings.github.connect')),
        'github failure' => expect($response->json('details.githubStatus'))->toBe(422),
        default => null,
    };
})->with([
    'not linked' => ['not linked', 409, 'not_linked'],
    'read access' => ['read access', 403, 'no_write_access'],
    'not the requester' => ['not the requester', 403, 'not_requester'],
    'access check error' => ['access check error', 503, 'access_check_failed'],
    'github failure' => ['github failure', 503, 'github_failed'],
]);

it('never shows internal error text when opening the pull request fails', function (Throwable $failure) {
    coOwnerGitHubFakes('write', ['api.github.com/repos/example/api/pulls' => fn () => throw $failure]);

    $response = $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'github_failed')
        ->assertJsonPath('message', 'Yak could not open the pull request. Try again in a moment.');

    expect($response->json('details'))->toBeEmpty()
        ->and($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
})->with([
    'runtime exception' => [fn () => new RuntimeException('{"secret":"body"}')],
    'connection exception' => [fn () => new ConnectionException('cURL error 28 for https://api.github.com/repos/example/api/pulls')],
]);

it('wraps the GitHub message when GitHub refuses the pull request', function () {
    coOwnerGitHubFakes('write', ['api.github.com/repos/example/api/pulls' => Http::response(['message' => 'Validation Failed'], 422)]);

    $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertStatus(503)
        ->assertJsonPath('message', 'GitHub refused the pull request: Validation Failed');
});

it('answers 404 for a task with no co-owner request', function () {
    $plain = YakTask::factory()->create(['public_id' => 'plain000', 'started_by_user_id' => $this->user->id, 'thread_id' => null]);

    foreach (['approve', 'request-changes', 'reject', 'withdraw'] as $decision) {
        $this->postJson(decisionPath($plain, $decision), ['feedback' => 'x'], $this->headers)->assertNotFound()->assertJsonPath('error', 'not_found');
    }
});

it('validates the feedback', function (array $body) {
    $response = $this->postJson(decisionPath($this->task, 'request-changes'), $body, $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    expect($response->json('details.errors'))->toHaveKey('feedback');

    expect($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
})->with([
    'missing' => [[]],
    'too long' => [['feedback' => str_repeat('a', 5001)]],
]);

it('waits at most five seconds for the task lock', function (string $decision) {
    YakTask::updating(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->postJson(decisionPath($this->task, $decision), [], $this->headers)->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is still handling another request for this task. Try again in a moment.');

    expect($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Pending)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
    Bus::assertNotDispatched(SendCoOwnerMessagesJob::class);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/pulls'));
})->with(['approve', 'reject']);

it('bounds the write access check', function () {
    $recorded = [];
    Http::swap(new Factory);
    Http::fake([
        'api.github.com/*' => function (HttpRequest $request, array $options) use (&$recorded) {
            $recorded[] = $options;
            throw new ConnectionException('Operation timed out');
        },
    ] + gitHubTokenFake());

    $this->postJson(decisionPath($this->task, 'approve'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'access_check_failed');

    expect($recorded)->not->toBeEmpty()
        ->and($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
    foreach ($recorded as $options) {
        expect($options['timeout'])->toBe(3)->and($options['connect_timeout'])->toBe(2);
    }
});

describe('who may decide', function () {
    beforeEach(function () {
        $this->thread = privateThreadOf($this->user, ['public_id' => 'd0e515ef']);
        $this->task->update(['thread_id' => $this->thread->id]);
        $this->routes = ['approve' => [], 'request-changes' => ['feedback' => 'Please keep the old webhook path.'], 'reject' => [], 'withdraw' => []];
    });

    it('refuses a share recipient on every decision', function () {
        $recipient = shareThreadWith($this->thread, $this->user);

        foreach ($this->routes as $route => $body) {
            $this->postJson(decisionPath($this->task, $route), $body, phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');
        }

        expect($this->coOwnerRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Pending)
            ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
        Bus::assertNotDispatched(SendCoOwnerMessagesJob::class);
        Bus::assertNotDispatched(RunFollowUpJob::class);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/pulls'));
    });

    it('answers a hidden task exactly like a missing one', function () {
        $stranger = phoneHeadersFor(User::factory()->create());
        $missing = (clone $this->task)->forceFill(['public_id' => 'nope0000']);

        foreach ($this->routes as $route => $body) {
            $hidden = $this->postJson(decisionPath($this->task, $route), $body, $stranger)->assertNotFound();
            $unknown = $this->postJson(decisionPath($missing, $route), $body, $stranger)->assertNotFound();
            expect($hidden->json())->toBe($unknown->json());
            $numeric = $this->postJson(str_replace($this->task->public_id, (string) $this->task->id, decisionPath($this->task, $route)), $body, $stranger)->assertNotFound();
            expect($numeric->json())->toBe($unknown->json());
        }
    });

    it('refuses without a token, with a revoked phone and with a computer token', function () {
        foreach ($this->routes as $route => $body) {
            $this->postJson(decisionPath($this->task, $route), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        }

        [$device, $headers] = phoneWithHeaders(['user_id' => $this->user->id]);
        $device->update(['revoked_at' => now()]);
        $this->postJson(decisionPath($this->task, 'approve'), [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

        [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->user->id], isPhone: false);
        $this->postJson(decisionPath($this->task, 'approve'), [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    });
});
