<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\TaskRunKind;
use App\Enums\ThreadVisibility;
use App\Jobs\SendNotificationJob;
use App\Jobs\Threads\OpenRepositoryPullRequestJob;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\PullRequests\RepositoryCiRetry;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Queue;

function ciRetryThread(): array
{
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web']);
    $thread = Thread::factory()->create();
    $primary = ThreadRepository::factory()->for($thread)->withPullRequest(4)->create(['repo' => 'acme/api', 'sandbox_path' => '/workspace', 'branch' => 'yak/x']);
    $extra = ThreadRepository::factory()->for($thread)->changes()->withPullRequest(4)->create(['repo' => 'acme/web', 'sandbox_path' => '/workspace/web', 'branch' => 'yak/x']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return [$thread, $primary, $extra, $task];
}

it('counts failures per repo and dispatches a retry until the limit', function () {
    Queue::fake();
    config()->set('yak.max_attempts', 2);
    [, , $extra] = ciRetryThread();
    $other = ThreadRepository::factory()->for($extra->thread)->changes()->withPullRequest(9)->create(['repo' => 'acme/docs']);
    $retry = app(RepositoryCiRetry::class);

    $retry->handleResult($extra, false, 'boom', 'abc');
    $retry->handleResult($extra, false, 'boom', 'abd');
    $retry->handleResult($extra, false, 'boom', 'abe');
    $retry->handleResult($other, false, 'boom', 'def');

    // The second retry of the same row is absorbed by the unique lock.
    Queue::assertPushed(RetryRepositoryCiJob::class, 2);
    Queue::assertPushed(SendNotificationJob::class, 1);
    expect($extra->refresh()->ci_retry_count)->toBe(2)->and($extra->ci_status)->toBe('failing')
        ->and($other->refresh()->ci_retry_count)->toBe(1);
});

it('names neither repo nor pull request in the retry-limit notification of a private thread', function () {
    Queue::fake();
    config()->set('yak.max_attempts', 1);
    [$thread, , $extra] = ciRetryThread();
    $thread->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => User::factory()->create()->id])->save();
    $retry = app(RepositoryCiRetry::class);

    $retry->handleResult($extra, false, 'boom', 'a');
    $retry->handleResult($extra, false, 'boom', 'b');

    Queue::assertPushed(SendNotificationJob::class, fn ($job) => ! str_contains($job->message, 'acme/web') && ! str_contains($job->message, '#4'));
});

it('ignores a context repo', function () {
    Queue::fake();
    [$thread] = ciRetryThread();
    $context = ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs', 'branch' => 'yak/x']);

    app(RepositoryCiRetry::class)->handleResult($context, false, 'boom', null);

    Queue::assertNothingPushed();
    expect($context->refresh()->ci_status)->toBeNull();
});

it('opens the pull request when CI passes on an extra repo without one', function () {
    Queue::fake();
    [, , $extra] = ciRetryThread();
    $extra->forceFill(['pull_request_url' => null, 'pull_request_number' => null, 'pull_request_state' => null])->save();

    app(RepositoryCiRetry::class)->handleResult($extra, true, null, 'abc');

    Queue::assertPushed(OpenRepositoryPullRequestJob::class);
    expect($extra->refresh()->ci_status)->toBe('passing');
});

it('resets only the failing repo and starts a turn naming it', function () {
    [$thread, , $extra] = ciRetryThread();
    $this->mock(ThreadSandbox::class)->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldReceive('start')->once()->withArgs(fn ($task, $prompt, $driver, $model, $kind) => $kind === TaskRunKind::RepositoryEvent && str_contains($prompt, 'acme/web#4') && str_contains($prompt, '/workspace/web') && str_contains($prompt, 'lint failed'));

    $sandboxes = new FakeSandboxManager;
    $sandboxes->create($thread);
    $sandboxes->start($thread);
    app()->instance(SandboxManager::class, $sandboxes);

    (new RetryRepositoryCiJob($extra->id, 'lint failed'))->handle($turns, app(ThreadSandbox::class), $sandboxes);

    $commands = collect($sandboxes->calls)->where('operation', 'exec')->map(fn ($call) => implode(' ', $call['arguments']['command']));
    expect($commands)->toHaveCount(1)
        ->and($commands->first())->toContain("cd '/workspace/web'")->toContain("reset --hard origin/'yak/x'")
        ->and($commands->first())->not->toContain('cd /workspace &&');
});

it('waits while another turn runs so two repos retry one after the other', function () {
    [$thread, , $extra] = ciRetryThread();
    $sandbox = $this->mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldReceive('start')->once()->andThrow(new ThreadBusy('busy'));
    $sandboxes = new FakeSandboxManager;
    $sandboxes->create($thread);
    $sandboxes->start($thread);

    $job = (new RetryRepositoryCiJob($extra->id))->withFakeQueueInteractions();
    $job->handle($turns, $sandbox, $sandboxes);

    $job->assertReleased(30);
});
