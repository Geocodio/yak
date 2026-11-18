<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RenderVideoJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\Artifact;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Threads\ThreadFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../../../Support/App/ChangesHelpers.php';

beforeEach(function () {
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function appActionTask(User $user, array $attributes = []): YakTask
{
    return YakTask::factory()->create([
        'mode' => TaskMode::Fix, 'repo' => 'web', 'pr_url' => null, 'status' => TaskStatus::Failed, 'started_by_user_id' => $user->id,
        ...$attributes,
    ]);
}

function appActionThread(YakTask $task): Thread
{
    return Thread::query()->findOrFail($task->thread_id);
}

function appActionBody(string $route): array
{
    return match ($route) {
        'move', 'repository-answer' => ['repo' => 'api'],
        'co-owner/request-changes' => ['feedback' => 'Please keep the old webhook path.'],
        'co-owner/tag' => ['user_id' => 1],
        default => [],
    };
}

dataset('thread action routes', [
    'retry', 'cancel', 'move', 'request-review', 'retry-render', 'repository-answer', 'setup-and-continue',
    'co-owner/approve', 'co-owner/request-changes', 'co-owner/reject', 'co-owner/withdraw', 'co-owner/check-access', 'co-owner/tag', 'co-owner/decline',
]);

it('refuses a viewer who cannot drive the candidates and hides them from a stranger', function () {
    $task = appActionTask($this->user, ['early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
    $thread = appActionThread($task)->forceFill(['visibility' => 'private', 'owner_user_id' => $this->user->id]);
    $thread->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->getJson(appThreadPath($thread, '/co-owner/candidates'), phoneHeadersFor($viewer))->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->getJson(appThreadPath($thread, '/co-owner/candidates'), phoneHeadersFor(User::factory()->create()))->assertNotFound();
    $this->getJson(appThreadPath($thread, '/co-owner/candidates'), $this->headers)->assertOk()->assertJsonStructure(['candidates', 'isComplete']);
});

it('checks the thread state before the body of a move and a repository answer', function (string $route) {
    $task = appActionTask($this->user);
    $thread = appActionThread($task);
    $thread->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);

    $this->postJson(appThreadPath($thread, "/{$route}"), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'thread_closed');
})->with(['move', 'repository-answer']);

it('refuses a viewer who cannot drive and hides the thread from a stranger', function (string $route) {
    $task = appActionTask($this->user);
    $thread = appActionThread($task)->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]);
    $thread->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);
    $body = appActionBody($route);

    $this->postJson(appThreadPath($thread, "/{$route}"), $body, phoneHeadersFor($viewer))->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->postJson(appThreadPath($thread, "/{$route}"), $body, phoneHeadersFor(User::factory()->create()))->assertNotFound();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
})->with('thread action routes');

it('answers a thread without a fitting task with its nothing-to code', function (string $route, string $code) {
    $thread = Thread::factory()->create(['repo' => 'web']);

    $this->postJson(appThreadPath($thread, "/{$route}"), appActionBody($route), $this->headers)
        ->assertStatus(422)->assertJsonPath('error', $code)->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'This thread has nothing to '));
})->with([
    ['retry', 'nothing_to_retry'],
    ['setup-and-continue', 'nothing_to_retry'],
    ['cancel', 'nothing_to_cancel'],
    ['move', 'nothing_to_move'],
    ['request-review', 'nothing_to_review'],
    ['retry-render', 'nothing_to_render'],
    ['repository-answer', 'nothing_to_answer'],
    ['co-owner/approve', 'nothing_to_decide'],
    ['co-owner/request-changes', 'nothing_to_decide'],
    ['co-owner/reject', 'nothing_to_decide'],
    ['co-owner/withdraw', 'nothing_to_decide'],
    ['co-owner/check-access', 'nothing_to_decide'],
    ['co-owner/tag', 'nothing_to_decide'],
    ['co-owner/decline', 'nothing_to_decide'],
]);

it('refuses a closed thread', function (string $route) {
    $task = appActionTask($this->user, ['pr_url' => 'https://github.com/o/r/pull/1', 'early_warning' => ['areas' => [], 'state' => 'shown']]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => Repository::factory()->create()->id]);
    $thread = appActionThread($task);
    $thread->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);

    $this->postJson(appThreadPath($thread, "/{$route}"), appActionBody($route), $this->headers)
        ->assertStatus(409)->assertJsonPath('error', 'thread_closed')->assertJsonPath('message', 'This thread is closed.');

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
})->with(['retry', 'setup-and-continue', 'cancel', 'move', 'request-review', 'retry-render', 'co-owner/approve', 'co-owner/tag']);

it('asks for a repository on a thread without one', function (string $route) {
    $task = appActionTask($this->user, ['pr_url' => 'https://github.com/o/r/pull/1']);
    appActionThread($task)->update(['repo' => Thread::UNKNOWN_REPOSITORY]);

    $this->postJson(appThreadPath(appActionThread($task), "/{$route}"), [], $this->headers)
        ->assertStatus(409)->assertJsonPath('error', 'no_repository')->assertJsonPath('message', 'Pick a repository first.');

    Queue::assertNothingPushed();
})->with(['retry', 'setup-and-continue', 'request-review', 'retry-render']);

it('retries the failed head task and answers with the thread show', function () {
    $task = appActionTask($this->user, ['error_log' => 'boom', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.']);
    $thread = appActionThread($task);

    $this->postJson(appThreadPath($thread, '/retry'), [], $this->headers)->assertOk()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('thread.id', $thread->public_id)
        ->assertJsonPath('actions.canRetry', false)
        ->assertJsonStructure(['messages', 'composer', 'request', 'runDetails']);

    Queue::assertPushed(RunYakJob::class, 1);
    expect($task->fresh()->status)->toBe(TaskStatus::Pending)->and($task->fresh()->stop_kind)->toBeNull();

    $this->postJson(appThreadPath($thread, '/retry'), [], $this->headers)->assertOk()->assertJsonPath('status', 'queued');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('refuses to retry a finished task with the task code', function () {
    $task = appActionTask($this->user, ['status' => TaskStatus::Success]);

    $this->postJson(appThreadPath(appActionThread($task), '/retry'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'task_not_retryable');
});

it('cancels the head task once for two requests', function () {
    $sandbox = Mockery::mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('destroy')->once();
    $this->app->instance(IncusSandboxManager::class, $sandbox);
    $task = appActionTask($this->user, ['status' => TaskStatus::Running]);
    $thread = appActionThread($task);

    $this->postJson(appThreadPath($thread, '/cancel'), [], $this->headers)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('stopped.kind', 'cancelled');
    $this->postJson(appThreadPath($thread, '/cancel'), [], $this->headers)->assertOk()->assertJsonPath('status', 'cancelled');

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('refuses to cancel a finished task with the task code', function () {
    $task = appActionTask($this->user, ['status' => TaskStatus::Success]);

    $this->postJson(appThreadPath(appActionThread($task), '/cancel'), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'task_not_cancellable');
});

it('moves the task and answers with the show of the new thread', function () {
    $task = appActionTask($this->user);
    $old = appActionThread($task);

    $response = $this->postJson(appThreadPath($old, '/move'), ['repo' => 'api'], $this->headers)->assertOk()
        ->assertJsonPath('status', 'moved')->assertJsonPath('thread.repo', 'api');

    $new = appActionThread($task->fresh());
    expect($response->json('thread.id'))->toBe($new->public_id)->and($new->id)->not->toBe($old->id)
        ->and($old->fresh()->isOpen())->toBeFalse()->and($task->fresh()->repo)->toBe('api');
});

it('keeps a private thread private after the move and answers with the new thread to its owner', function () {
    $task = appActionTask($this->user);
    $old = appActionThread($task);
    $old->forceFill(['visibility' => 'private', 'owner_user_id' => $this->user->id])->save();

    $response = $this->postJson(appThreadPath($old, '/move'), ['repo' => 'api'], $this->headers)->assertOk();

    $new = appActionThread($task->fresh());
    expect($new->visibility->value)->toBe('private')->and($response->json('thread.id'))->toBe($new->public_id);
});

it('attaches the repository on move when the thread has none and keeps its id', function () {
    $task = appActionTask($this->user, ['repo' => Thread::UNKNOWN_REPOSITORY]);
    $thread = appActionThread($task);
    $thread->update(['repo' => Thread::UNKNOWN_REPOSITORY]);

    $this->postJson(appThreadPath($thread, '/move'), ['repo' => 'api'], $this->headers)->assertOk()
        ->assertJsonPath('thread.id', $thread->public_id)->assertJsonPath('thread.repo', 'api')->assertJsonPath('thread.hasRepository', true);
});

it('validates the move body', function (array $body) {
    $task = appActionTask($this->user);

    $this->postJson(appThreadPath(appActionThread($task), '/move'), $body, $this->headers)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['repo']]]);

    expect($task->fresh()->repo)->toBe('web');
})->with([[[]], [['repo' => 'example/old']], [['repo' => ['a']]]]);

it('re-renders the walkthrough of the pull request task', function () {
    $task = appActionTask($this->user, ['status' => TaskStatus::Success, 'branch_name' => 'yak/x']);
    Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video']);
    $thread = appActionThread($task);

    $this->postJson(appThreadPath($thread, '/retry-render'), [], $this->headers)->assertOk()->assertJsonPath('status', 'rendering');
    $this->postJson(appThreadPath($thread, '/retry-render'), [], $this->headers)->assertOk()->assertJsonPath('status', 'rendering');

    Queue::assertPushed(RenderVideoJob::class, 1);
});

it('starts repository setup and marks the head task to continue', function () {
    $repository = Repository::factory()->create(['slug' => 'fresh', 'setup_status' => 'failed', 'sandbox_snapshot' => null, 'is_active' => true]);
    $task = appActionTask($this->user, ['repo' => $repository->slug, 'error_log' => 'not set up']);

    $this->postJson(appThreadPath(appActionThread($task), '/setup-and-continue'), [], $this->headers)->assertOk()->assertJsonPath('status', 'queued');

    Queue::assertPushed(SetupYakJob::class, 1);
    expect($task->fresh()->resume_after_setup_at)->not->toBeNull();
});

function appActionReviewTask(User $user): YakTask
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 12345);
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true, 'git_url' => 'https://github.com/geocodio/api.git', 'github_full_name' => 'geocodio/api']);
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/geocodio/api/pulls/77' => Http::response([
            'html_url' => 'https://github.com/geocodio/api/pull/77', 'number' => 77, 'title' => 'Some PR', 'body' => '', 'state' => 'open', 'draft' => false,
            'user' => ['login' => 'mathias'], 'head' => ['ref' => 'feat/x', 'sha' => 'new-head-sha'], 'base' => ['ref' => 'main', 'sha' => 'base-sha'],
        ]),
    ]);

    return appActionTask($user, ['repo' => 'geocodio/api', 'status' => TaskStatus::Success, 'branch_name' => 'yak/x', 'pr_url' => 'https://github.com/geocodio/api/pull/77', 'pr_number' => 77]);
}

it('requests a review of the pull request of the thread and refuses a repeat while it runs', function () {
    $task = appActionReviewTask($this->user);
    $thread = appActionThread($task);

    $this->postJson(appThreadPath($thread, '/request-review'), [], $this->headers)->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('thread.id', $thread->public_id);

    expect(YakTask::query()->where('mode', TaskMode::Review)->where('id', '!=', $task->id)->count())->toBe(1);

    $this->postJson(appThreadPath($thread, '/request-review'), [], $this->headers)
        ->assertStatus(409)->assertJsonPath('error', 'review_not_requested');
    expect(YakTask::query()->where('mode', TaskMode::Review)->where('id', '!=', $task->id)->count())->toBe(1);
});

it('answers a thread without a pull request with nothing to review', function () {
    $task = appActionTask($this->user);

    $this->postJson(appThreadPath(appActionThread($task), '/request-review'), [], $this->headers)->assertStatus(422)->assertJsonPath('error', 'nothing_to_review');
});

function appActionWithoutRepository(User $requester, ?array $options): array
{
    $task = YakTask::factory()->create(['repo' => 'unknown', 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $requester->id, 'clarification_options' => $options, 'session_id' => null]);

    return [app(ThreadFactory::class)->forRequestWithoutRepository($task), $task];
}

it('attaches the chosen repository and starts the run', function () {
    [$thread, $task] = appActionWithoutRepository($this->user, ['web', 'api']);

    $this->postJson(appThreadPath($thread, '/repository-answer'), ['repo' => 'web'], $this->headers)->assertOk()
        ->assertJsonPath('status', 'started')->assertJsonPath('thread.repo', 'web')->assertJsonPath('thread.hasRepository', true)->assertJsonPath('repositoryQuestion', null);

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    Queue::assertPushed(RunYakJob::class, 1);
});

it('refuses a repository that was not offered and one picked twice', function () {
    [$thread] = appActionWithoutRepository($this->user, ['web']);

    $this->postJson(appThreadPath($thread, '/repository-answer'), ['repo' => 'api'], $this->headers)->assertStatus(409)->assertJsonPath('error', 'repository_not_offered');
    $this->postJson(appThreadPath($thread, '/repository-answer'), [], $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    expect($thread->fresh()->hasRepository())->toBeFalse();

    $this->postJson(appThreadPath($thread, '/repository-answer'), ['repo' => 'web'], $this->headers)->assertOk();
    $this->postJson(appThreadPath($thread, '/repository-answer'), ['repo' => 'web'], $this->headers)->assertStatus(409)->assertJsonPath('error', 'repository_already_picked');
});

function appActionCoOwnerFakes(): void
{
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/user/4242' => Http::response(['login' => 'mhansen']),
        'api.github.com/repos/o/r/collaborators/*/permission' => Http::response(['role_name' => 'write']),
        'api.github.com/repos/o/r/pulls?*' => Http::response([]),
        'api.github.com/repos/o/r/pulls' => Http::response(['number' => 7, 'html_url' => 'https://github.com/o/r/pull/7']),
        'api.github.com/repos/o/r/issues/*/labels' => Http::response([]),
        'api.github.com/repos/o/r/pulls/*/requested_reviewers' => Http::response([]),
        'api.github.com/repos/o/r/git/refs/*' => Http::response([], 204),
    ]);
}

describe('co-owner decisions', function () {
    beforeEach(function () {
        configureFakeGitHubApp();
        config()->set('yak.pr_title_writer.enabled', false);
        config()->set('app.url', 'https://yak.example.com');
        Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class, RunFollowUpJob::class]);
        $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
        $this->coOwner = User::factory()->withGitHub()->create(['name' => 'Mathias Hansen']);
        $this->coOwnerHeaders = phoneHeadersFor($this->coOwner);
        $this->task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'o-r', 'branch_name' => 'yak/x', 'source' => 'slack', 'started_by_user_id' => $this->user->id]);
        $this->thread = appActionThread($this->task);
        $this->request = CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id, 'asked_user_ids' => [$this->coOwner->id]]);
        appActionCoOwnerFakes();
    });

    it('approves, opens the pull request and answers a repeat the same', function () {
        $this->postJson(appThreadPath($this->thread, '/co-owner/approve'), [], $this->coOwnerHeaders)->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('coOwner.status', 'approved');
        $this->postJson(appThreadPath($this->thread, '/co-owner/approve'), [], $this->coOwnerHeaders)->assertOk()->assertJsonPath('status', 'approved');

        expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved);
    });

    it('requests changes, rejects and withdraws', function (string $decision, string $status, string $actor, array $body) {
        $headers = $actor === 'requester' ? $this->headers : $this->coOwnerHeaders;

        $this->postJson(appThreadPath($this->thread, "/co-owner/{$decision}"), $body, $headers)->assertOk()->assertJsonPath('status', $status);

        expect($this->request->fresh()->status->value)->toBe($status);
    })->with([
        'request changes' => ['request-changes', 'changes_requested', 'co-owner', ['feedback' => 'Use the existing guard.']],
        'reject' => ['reject', 'rejected', 'co-owner', []],
        'withdraw' => ['withdraw', 'withdrawn', 'requester', []],
    ]);

    it('validates the feedback', function () {
        $this->postJson(appThreadPath($this->thread, '/co-owner/request-changes'), ['feedback' => ''], $this->coOwnerHeaders)
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['feedback']]]);
    });

    it('maps a refusal to the code of the task route', function () {
        appActionCoOwnerFakes();
        $this->request->update(['status' => CoOwnerRequestStatus::Rejected]);

        $this->postJson(appThreadPath($this->thread, '/co-owner/approve'), [], $this->coOwnerHeaders)->assertStatus(409)->assertJsonPath('error', 'already_resolved');
    });

    it('forgets the cached write access on check again', function () {
        $key = "co-owner:write-access:{$this->repository->id}:4242";
        Cache::put($key, 'read', now()->addMinutes(10));

        $this->postJson(appThreadPath($this->thread, '/co-owner/check-access'), [], $this->coOwnerHeaders)->assertOk()->assertJsonPath('status', 'checked');

        expect(Cache::get($key))->toBe('write');
    });

    it('decides on the task holding the latest request, not the head task', function () {
        $head = YakTask::factory()->create(['thread_id' => $this->thread->id, 'parent_task_id' => $this->task->id, 'repo' => 'o-r']);

        $this->postJson(appThreadPath($this->thread, '/co-owner/reject'), [], $this->coOwnerHeaders)->assertOk();

        expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected)->and($head->fresh()->status)->not->toBe($this->task->fresh()->status);
    });
});

describe('early warning', function () {
    beforeEach(function () {
        configureFakeGitHubApp();
        config()->set('app.url', 'https://yak.example.com');
        $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r']);
        $this->warned = YakTask::factory()->create(['repo' => 'o-r', 'started_by_user_id' => $this->user->id, 'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
        $this->warnedThread = appActionThread($this->warned);
        $this->writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
        Cache::put("co-owner:write-access:{$this->repository->id}:1", 'write', now()->addMinutes(10));
    });

    it('lists the candidates', function () {
        $this->getJson(appThreadPath($this->warnedThread, '/co-owner/candidates'), $this->headers)->assertOk()->assertJsonFragment(['id' => $this->writer->id, 'name' => 'Wanda Writer']);
    });

    it('lets the requester tag a co-owner and answers with the thread', function () {
        $this->postJson(appThreadPath($this->warnedThread, '/co-owner/tag'), ['user_id' => $this->writer->id], $this->headers)->assertOk()
            ->assertJsonPath('status', 'tagged')->assertJsonPath('earlyWarning.state', 'tagged');

        expect($this->warned->fresh()->tagged_co_owner_user_id)->toBe($this->writer->id);
    });

    it('lets only the requester decline', function () {
        $this->postJson(appThreadPath($this->warnedThread, '/co-owner/decline'), [], phoneHeadersFor($this->writer))->assertStatus(403)->assertJsonPath('error', 'not_requester');
        expect($this->warned->fresh()->early_warning['state'])->toBe('shown');

        $this->postJson(appThreadPath($this->warnedThread, '/co-owner/decline'), [], $this->headers)->assertOk()->assertJsonPath('status', 'declined');
        expect($this->warned->fresh()->early_warning['state'])->toBe('declined');
    });
});

describe('the installed app keeps its task routes for a task whose thread is open', function () {
    it('retries', function () {
        $task = appActionTask($this->user);
        expect(appActionThread($task)->isOpen())->toBeTrue();

        $this->postJson(appTaskPath($task, '/retry'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

        expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    });

    it('cancels', function () {
        $sandbox = Mockery::mock(IncusSandboxManager::class);
        $sandbox->shouldReceive('destroy')->once();
        $this->app->instance(IncusSandboxManager::class, $sandbox);
        $task = appActionTask($this->user, ['status' => TaskStatus::Running]);

        $this->postJson(appTaskPath($task, '/cancel'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'cancelled']);

        expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    });

    it('re-runs a review', function () {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        config()->set('yak.channels.github.app_id', '999');
        config()->set('yak.channels.github.private_key', $privateKey);
        config()->set('yak.channels.github.installation_id', 12345);
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String()]),
            'api.github.com/repos/web/pulls/41' => Http::response(['number' => 41, 'head' => ['sha' => 'abc', 'ref' => 'feat/x'], 'base' => ['sha' => 'def', 'ref' => 'main']]),
        ]);
        $task = appActionTask($this->user, ['mode' => TaskMode::Review, 'status' => TaskStatus::Success, 'context' => json_encode(['pr_number' => 41])]);

        $this->postJson(appTaskPath($task, '/rerun-review'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'queued']);

        expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    });

    it('re-renders', function () {
        $task = appActionTask($this->user, ['status' => TaskStatus::Success]);
        Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video']);

        $this->postJson(appTaskPath($task, '/retry-render'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'rendering']);

        Queue::assertPushed(RenderVideoJob::class, 1);
    });

    it('reroutes', function () {
        $task = appActionTask($this->user);
        $sandbox = Mockery::mock(IncusSandboxManager::class);
        $sandbox->shouldReceive('destroy')->zeroOrMoreTimes();
        $this->app->instance(IncusSandboxManager::class, $sandbox);

        $this->postJson(appTaskPath($task, '/reroute'), ['repo' => 'api'], $this->headers)->assertOk()->assertExactJson(['status' => 'moved']);

        expect($task->fresh()->repo)->toBe('api');
    });

    it('answers questions', function () {
        $task = YakTask::factory()->withClarificationQuestions()->create(['started_by_user_id' => $this->user->id]);

        $this->postJson(appTaskPath($task, '/clarification-answers'), ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]]], $this->headers)->assertOk()->assertExactJson(['status' => 'sent']);

        Queue::assertPushed(RunYakJob::class, 1);
    });

    describe('co-owner decisions', function () {
        beforeEach(function () {
            configureFakeGitHubApp();
            config()->set('yak.pr_title_writer.enabled', false);
            config()->set('app.url', 'https://yak.example.com');
            Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class, RunFollowUpJob::class]);
            $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
            $this->user = User::factory()->withGitHub()->create(['name' => 'Test person']);
            $this->headers = phoneHeadersFor($this->user);
            $this->task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'o-r', 'branch_name' => 'yak/x', 'source' => 'slack', 'started_by_user_id' => $this->user->id]);
            $this->request = CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id, 'asked_user_ids' => [$this->user->id]]);
            appActionCoOwnerFakes();
            expect(appActionThread($this->task)->isOpen())->toBeTrue();
        });

        it('decides', function (string $decision, string $status, array $body) {
            $this->postJson(appTaskPath($this->task, "/co-owner/{$decision}"), $body, $this->headers)->assertOk()->assertExactJson(['status' => $status]);

            expect($this->request->fresh()->status->value)->toBe($status);
        })->with([
            'approve' => ['approve', 'approved', []],
            'request changes' => ['request-changes', 'changes_requested', ['feedback' => 'Use the existing guard.']],
            'reject' => ['reject', 'rejected', []],
            'withdraw' => ['withdraw', 'withdrawn', []],
        ]);
    });

    describe('early warning', function () {
        beforeEach(function () {
            configureFakeGitHubApp();
            config()->set('app.url', 'https://yak.example.com');
            $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r']);
            $this->warned = YakTask::factory()->create(['repo' => 'o-r', 'started_by_user_id' => $this->user->id, 'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
            $this->writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
            Cache::put("co-owner:write-access:{$this->repository->id}:1", 'write', now()->addMinutes(10));
            expect(appActionThread($this->warned)->isOpen())->toBeTrue();
        });

        it('lists candidates, tags and declines', function () {
            $this->getJson(appTaskPath($this->warned, '/co-owner/candidates'), $this->headers)->assertOk()->assertJsonFragment(['id' => $this->writer->id]);
            $this->postJson(appTaskPath($this->warned, '/co-owner/tag'), ['user_id' => $this->writer->id], $this->headers)->assertOk()->assertExactJson(['status' => 'tagged']);
            expect($this->warned->fresh()->tagged_co_owner_user_id)->toBe($this->writer->id);
        });

        it('declines', function () {
            $this->postJson(appTaskPath($this->warned, '/co-owner/decline'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'declined']);
            expect($this->warned->fresh()->early_warning['state'])->toBe('declined');
        });
    });

    it('lists the task and shows it', function () {
        $task = YakTask::factory()->awaitingClarification()->create(['started_by_user_id' => $this->user->id]);

        $this->getJson('/api/app/v1/tasks', $this->headers)->assertOk();
        $this->getJson(appTaskPath($task), $this->headers)->assertOk();
    });
});
