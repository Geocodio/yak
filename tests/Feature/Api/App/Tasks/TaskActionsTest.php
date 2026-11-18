<?php

use App\Console\Commands\TimeoutAwaitingCiCommand;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\VideoRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContractFixture;

/**
 * @param  array<string, mixed>  $attributes
 */
function actionTask(User $user, array $attributes = []): YakTask
{
    return YakTask::factory()->create($attributes + [
        'public_id' => 'k3m9p2qa', 'repo' => 'example/api', 'mode' => TaskMode::Fix, 'status' => TaskStatus::Failed,
        'description' => 'Upgrade the payments SDK', 'started_by_user_id' => $user->id, 'thread_id' => null, 'pr_url' => null,
    ]);
}

function configureGitHubForReview(): void
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 12345);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user);
});

it('retries a failed task once for two requests', function () {
    $task = actionTask($this->user);

    $first = $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);
    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);
    Queue::assertPushed(RunYakJob::class, 1);

    $task->refresh()->update(['status' => TaskStatus::Running]);
    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);
    Queue::assertPushed(RunYakJob::class, 1);

    ContractFixture::assertMatches('app', 'task-retry', $first->json());
});

it('refuses to retry a finished task', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);

    $response = $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'task_not_retryable');

    Queue::assertNothingPushed();
    ContractFixture::assertMatches('app', 'error-task-not-retryable', $response->json());
});

it('resumes on the pushed branch and keeps the work', function () {
    $task = actionTask($this->user, ['branch_name' => 'yak/upgrade-payments-sdk', 'attempts' => 2]);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertJsonPath('status', 'queued');
    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertJsonPath('status', 'queued');

    Queue::assertPushed(RetryYakJob::class, 1);
    Queue::assertNotPushed(RunYakJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Retrying)->and($task->fresh()->attempts)->toBe(3);
});

it('resumes CI after a timeout and checks the build once', function () {
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api', 'ci_system' => 'github_actions']);
    Http::fake(['api.github.com/repos/example/api/actions/runs*' => Http::response(['workflow_runs' => [
        ['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'success'],
    ]])]);
    $task = actionTask($this->user, ['branch_name' => 'yak/upgrade-payments-sdk', 'attempts' => 1, 'error_log' => TimeoutAwaitingCiCommand::TIMEOUT_ERROR_PREFIX . ' 30 minutes']);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)->and($task->fresh()->error_log)->toBeNull();
    Queue::assertPushed(ProcessCIResultJob::class, 1);
    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->passed && $job->commitSha === 'new222');
    Queue::assertNotPushed(RunYakJob::class);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);
    Queue::assertPushed(ProcessCIResultJob::class, 1);
});

it('cancels once and answers the same for a repeat', function () {
    $sandbox = Mockery::mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('destroy')->once();
    $this->app->instance(IncusSandboxManager::class, $sandbox);
    $task = actionTask($this->user, ['status' => TaskStatus::Running]);

    $first = $this->postJson(appTaskPath($task, '/cancel'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'cancelled']);
    $this->postJson(appTaskPath($task, '/cancel'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'cancelled']);

    Queue::assertPushed(SendNotificationJob::class, 1);
    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    ContractFixture::assertMatches('app', 'task-cancel', $first->json());
});

it('refuses to cancel a finished task', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);

    $this->postJson(appTaskPath($task, '/cancel'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'task_not_cancellable');

    expect($task->fresh()->status)->toBe(TaskStatus::Success);
});

describe('rerun review', function () {
    function appReviewTask(User $user, array $attributes = []): YakTask
    {
        return actionTask($user, $attributes + ['mode' => TaskMode::Review, 'status' => TaskStatus::Success, 'context' => json_encode(['pr_number' => 41])]);
    }

    it('queues the review again', function () {
        configureGitHubForReview();
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String()]),
            '*' => Http::response([
                'number' => 41, 'head' => ['sha' => 'abc123', 'ref' => 'feature/x'], 'base' => ['sha' => 'def456', 'ref' => 'main'],
                'user' => ['login' => 'test-person'], 'title' => 'Upgrade the payments SDK', 'body' => 'Body',
            ]),
        ]);
        $task = appReviewTask($this->user);

        $response = $this->postJson(appTaskPath($task, '/rerun-review'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(RunYakReviewJob::class, 1);
        expect($task->fresh()->status)->toBe(TaskStatus::Pending);
        ContractFixture::assertMatches('app', 'task-rerun-review', $response->json());
    });

    it('refuses a task that is not a review, a queued review and an unknown pull request', function () {
        $this->postJson(appTaskPath(actionTask($this->user), '/rerun-review'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'not_a_review');
        $this->postJson(appTaskPath(appReviewTask($this->user, ['public_id' => 'q2w3e4rt', 'status' => TaskStatus::Pending]), '/rerun-review'), [], $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'review_already_queued');
        $this->postJson(appTaskPath(appReviewTask($this->user, ['public_id' => 'z8x7c6vb', 'context' => json_encode([])]), '/rerun-review'), [], $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'pull_request_unknown');

        Queue::assertNothingPushed();
    });

    it('answers 503 when GitHub does not answer and leaves the task alone', function () {
        configureGitHubForReview();
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String()]),
            '*' => Http::response('boom', 500),
        ]);
        $task = appReviewTask($this->user);

        $this->postJson(appTaskPath($task, '/rerun-review'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'github_unavailable');

        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);
        $this->postJson(appTaskPath($task, '/rerun-review'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'github_unavailable');

        Queue::assertNothingPushed();
        expect($task->fresh()->status)->toBe(TaskStatus::Success);
    });
});

it('queues one render for the same footage', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);
    $raw = Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video']);

    $first = $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'rendering']);
    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'rendering']);
    Queue::assertPushed(RenderVideoJob::class, 1);

    $this->mock(VideoRenderer::class)->shouldNotReceive('render');
    (new RenderVideoJob($raw->id))->handle(app(VideoRenderer::class));
    expect($raw->fresh()->render_requested_at)->toBeNull();

    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk();
    Queue::assertPushed(RenderVideoJob::class, 2);

    ContractFixture::assertMatches('app', 'task-retry-render', $first->json());
});

it('clears the render request when the render fails for good', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);
    $raw = Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video', 'render_requested_at' => now()]);

    (new RenderVideoJob($raw->id))->failed(new RuntimeException('renderer crashed'));

    expect($raw->fresh()->render_requested_at)->toBeNull();
});

it('counts a render request older than thirty minutes as gone', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);
    Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video', 'render_requested_at' => now()->subMinutes(31)]);

    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk();

    Queue::assertPushed(RenderVideoJob::class, 1);
});

it('refuses a render without footage', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);

    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'nothing_to_render');

    Queue::assertNothingPushed();
});

it('moves a task to another repository', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    Repository::factory()->create(['slug' => 'example/web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'example/old', 'is_active' => false]);
    $task = actionTask($this->user, ['status' => TaskStatus::Running]);
    $sandbox = Mockery::mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('destroy')->once();
    $this->app->instance(IncusSandboxManager::class, $sandbox);

    $response = $this->postJson(appTaskPath($task, '/reroute'), ['repo' => 'example/web'], $this->headers)->assertOk()->assertExactJson(['status' => 'moved']);
    $this->postJson(appTaskPath($task, '/reroute'), ['repo' => 'example/web'], $this->headers)->assertOk()->assertExactJson(['status' => 'moved']);

    Queue::assertPushed(RunYakJob::class, 1);
    expect($task->fresh()->repo)->toBe('example/web')->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    ContractFixture::assertMatches('app', 'task-reroute', $response->json());

    $this->postJson(appTaskPath($task, '/reroute'), ['repo' => 'example/old'], $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['repo']]]);
    $this->postJson(appTaskPath($task, '/reroute'), ['repo' => str_repeat('a', 256)], $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['repo']]]);
    $this->postJson(appTaskPath($task, '/reroute'), [], $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['repo']]]);
});

it('refuses to move a task that has a pull request', function () {
    Repository::factory()->create(['slug' => 'example/web', 'is_active' => true]);
    $task = actionTask($this->user, ['pr_url' => 'https://github.com/example/api/pull/41']);

    $this->postJson(appTaskPath($task, '/reroute'), ['repo' => 'example/web'], $this->headers)->assertStatus(409)->assertJsonPath('error', 'task_not_reroutable');
});

it('answers a task that vanished before the lock like a missing one', function () {
    $task = actionTask($this->user);
    $missing = $this->postJson(appTaskPath((clone $task)->forceFill(['public_id' => 'nope0000']), '/retry'), [], $this->headers)->assertNotFound();
    YakTask::retrieved(fn () => YakTask::withoutGlobalScopes()->whereKey($task->id)->delete());

    $vanished = $this->postJson(appTaskPath($task, '/cancel'), [], $this->headers)->assertNotFound();

    expect($vanished->json())->toBe($missing->json());
});

it('answers 503 unavailable when a write waits too long for the task lock', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Running]);
    YakTask::updating(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->postJson(appTaskPath($task, '/cancel'), [], $this->headers)->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is still handling another request for this task. Try again in a moment.');
});

describe('who may act', function () {
    beforeEach(function () {
        Repository::factory()->create(['slug' => 'example/web', 'is_active' => true]);
        $this->thread = privateThreadOf($this->user, ['public_id' => 'd0e515ef']);
        $this->task = actionTask($this->user, ['thread_id' => $this->thread->id]);
        $this->routes = ['retry' => [], 'cancel' => [], 'rerun-review' => [], 'retry-render' => [], 'reroute' => ['repo' => 'example/web']];
    });

    it('refuses a share recipient on every action', function () {
        $recipient = shareThreadWith($this->thread, $this->user);

        foreach ($this->routes as $route => $body) {
            $this->postJson(appTaskPath($this->task, "/{$route}"), $body, phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');
        }

        Queue::assertNothingPushed();
        expect($this->task->fresh()->status)->toBe(TaskStatus::Failed);
    });

    it('answers a hidden task exactly like a missing one', function () {
        $stranger = phoneHeadersFor(User::factory()->create());
        $missing = (clone $this->task)->forceFill(['public_id' => 'nope0000']);

        foreach ($this->routes as $route => $body) {
            $hidden = $this->postJson(appTaskPath($this->task, "/{$route}"), $body, $stranger)->assertNotFound();
            $unknown = $this->postJson(appTaskPath($missing, "/{$route}"), $body, $stranger)->assertNotFound();
            expect($hidden->json())->toBe($unknown->json());
        }

        Queue::assertNothingPushed();
    });

    it('refuses without a token, with a revoked phone and with a computer token', function () {
        foreach ($this->routes as $route => $body) {
            $this->postJson(appTaskPath($this->task, "/{$route}"), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
        }

        [$device, $headers] = phoneWithHeaders(['user_id' => $this->user->id]);
        $device->update(['revoked_at' => now()]);
        $this->postJson(appTaskPath($this->task, '/retry'), [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

        [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->user->id], isPhone: false);
        $this->postJson(appTaskPath($this->task, '/retry'), [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    });
});

function timeoutCiTask(User $user): YakTask
{
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api', 'ci_system' => 'github_actions']);

    return actionTask($user, ['branch_name' => 'yak/upgrade-payments-sdk', 'attempts' => 1, 'error_log' => TimeoutAwaitingCiCommand::TIMEOUT_ERROR_PREFIX . ' 30 minutes']);
}

it('reruns the failed CI jobs once even when a second retry starts while the first reads the runs', function () {
    $task = timeoutCiTask($this->user);
    $isNested = false;
    $reruns = 0;
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => function () use (&$reruns) {
            $reruns++;

            return Http::response([], 201);
        },
        'api.github.com/repos/example/api/actions/runs*' => function () use (&$isNested, $task) {
            if (! $isNested) {
                $isNested = true;
                $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);
            }

            return Http::response(['workflow_runs' => [['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]]);
        },
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

    expect($reruns)->toBe(1)->and($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertNotPushed(RetryYakJob::class);
});

it('falls back to the agent retry on the branch when GitHub refuses the rerun for good, once', function (int $refusal) {
    $task = timeoutCiTask($this->user);
    $reruns = 0;
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => function () use (&$reruns, $refusal) {
            $reruns++;

            return Http::response(['message' => 'refused'], $refusal);
        },
        'api.github.com/repos/example/api/actions/runs*' => Http::response(['workflow_runs' => [['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]]),
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);
    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

    expect($task->fresh()->status)->toBe(TaskStatus::Retrying)->and($task->fresh()->attempts)->toBe(2)->and($reruns)->toBe(1);
    Queue::assertPushed(RetryYakJob::class, 1);
})->with([403, 404, 422]);

it('falls back to the agent retry once when two retries start together and GitHub refuses the rerun', function () {
    $task = timeoutCiTask($this->user);
    $isNested = false;
    $reruns = 0;
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => function () use (&$reruns) {
            $reruns++;

            return Http::response(['message' => 'refused'], 403);
        },
        'api.github.com/repos/example/api/actions/runs*' => function () use (&$isNested, $task) {
            if (! $isNested) {
                $isNested = true;
                $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk();
            }

            return Http::response(['workflow_runs' => [['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]]);
        },
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk();

    expect($reruns)->toBe(1)->and($task->fresh()->status)->toBe(TaskStatus::Retrying);
    Queue::assertPushed(RetryYakJob::class, 1);
});

it('keeps waiting for CI when GitHub accepts one rerun and refuses another for good', function () {
    $task = timeoutCiTask($this->user);
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/2/rerun-failed-jobs' => Http::response([], 201),
        'api.github.com/repos/example/api/actions/runs/3/rerun-failed-jobs' => Http::response(['message' => 'disabled'], 403),
        'api.github.com/repos/example/api/actions/runs*' => Http::response(['workflow_runs' => [
            ['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure'],
            ['id' => 3, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure'],
        ]]),
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertNothingPushed();
});

it('treats a GitHub rate limit refusal as passing and puts the task back', function (array $headers) {
    $task = timeoutCiTask($this->user);
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => Http::response(['message' => 'rate limit'], 403, $headers),
        'api.github.com/repos/example/api/actions/runs*' => Http::response(['workflow_runs' => [['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]]),
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertStatus(503);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
})->with([[['retry-after' => '30']], [['x-ratelimit-remaining' => '0']]]);

it('puts the task back and answers 503 when the rerun fails in passing', function (int $status) {
    $task = timeoutCiTask($this->user);
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => Http::response(['message' => 'busy'], $status),
        'api.github.com/repos/example/api/actions/runs*' => Http::response(['workflow_runs' => [['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]]),
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'unavailable');

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)->and($task->fresh()->error_log)->toStartWith(TimeoutAwaitingCiCommand::TIMEOUT_ERROR_PREFIX);
    Queue::assertNothingPushed();
})->with([500, 502, 429]);

it('bounds every GitHub call of an app retry to two and three seconds', function () {
    $task = timeoutCiTask($this->user);
    $recorded = [];
    Http::fake([
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => function ($request, array $options) use (&$recorded) {
            $recorded[] = $options + ['u' => $request->url()];

            return Http::response([], 201);
        },
        'api.github.com/repos/example/api/actions/runs*' => function ($request, array $options) use (&$recorded) {
            $recorded[] = $options + ['u' => $request->url()];

            return Http::response(['workflow_runs' => [['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]]);
        },
    ]);

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk();

    expect(collect($recorded)->pluck('u')->unique()->count())->toBe(2);
    foreach ($recorded as $options) {
        expect($options['connect_timeout'])->toBe(2)->and($options['timeout'])->toBe(3);
    }
});

it('answers 503 and puts the task back when the resume job cannot be queued', function () {
    $task = actionTask($this->user, ['branch_name' => 'yak/upgrade-payments-sdk', 'attempts' => 2, 'error_log' => 'CI failed']);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE]);
    Queue::shouldReceive('push')->andThrow(new RuntimeException('redis down'));
    Queue::shouldReceive('connection')->andReturnSelf();

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'unavailable');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)->and($task->attempts)->toBe(2)->and($task->error_log)->toBe('CI failed');
});

it('logs that the restart failed when the resume job cannot be queued', function () {
    $task = actionTask($this->user, ['branch_name' => 'yak/upgrade-payments-sdk', 'attempts' => 2, 'error_log' => 'CI failed']);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE]);
    Queue::shouldReceive('push')->andThrow(new RuntimeException('redis down'));
    Queue::shouldReceive('connection')->andReturnSelf();

    $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertStatus(503);

    expect($task->logs()->where('message', 'Could not restart the task, it stays failed')->exists())->toBeTrue();
});

it('answers 503 and releases the render claim when the render cannot be queued', function () {
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);
    $raw = Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video']);
    Queue::shouldReceive('push')->andThrow(new RuntimeException('redis down'));
    Queue::shouldReceive('connection')->andReturnSelf();

    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertStatus(503)->assertJsonPath('error', 'unavailable');

    expect($raw->fresh()->render_requested_at)->toBeNull();
});

it('keeps the render request while a failed attempt waits for its second try', function () {
    Storage::fake('artifacts');
    $task = actionTask($this->user, ['status' => TaskStatus::Success]);
    $raw = Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm"]);
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm-bytes');
    Storage::disk('artifacts')->put("{$task->id}/storyboard.json", json_encode(['version' => 1, 'plan' => (object) [], 'events' => []]));
    $this->mock(VideoRenderer::class)->shouldReceive('render')->andThrow(new RuntimeException('renderer crashed'));

    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk();
    Queue::assertPushed(RenderVideoJob::class, 1);

    expect(fn () => (new RenderVideoJob($raw->id))->handle(app(VideoRenderer::class)))->toThrow(RuntimeException::class);

    $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'rendering']);
    Queue::assertPushed(RenderVideoJob::class, 1);
});
