<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function makeTestJobDouble(): object
{
    return new class
    {
        public ?YakTask $task = null;

        public bool $failed = false;

        public ?string $failMessage = null;

        public function fail(Throwable $e): void
        {
            $this->failed = true;
            $this->failMessage = $e->getMessage();
        }
    };
}

test('passes through when the repo has a sandbox snapshot', function () {
    Repository::factory()->create(['slug' => 'acme/ready-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/ready-repo']);

    $called = false;
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue()
        ->and($job->failed)->toBeFalse();
});

test('refuses when the repo has no sandbox snapshot', function () {
    Repository::factory()->pendingSetup()->create(['slug' => 'acme/unready']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/unready']);

    $called = false;
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($job->failed)->toBeTrue()
        ->and($job->failMessage)->toContain('not been set up yet');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('not been set up yet')
        ->and($task->completed_at)->not->toBeNull();
});

test('refuses when the repo row is missing entirely', function () {
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/ghost']);

    $called = false;
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($job->failed)->toBeTrue()
        ->and($job->failMessage)->toContain('not found');
});

test('passes through when the job has no task property', function () {
    $called = false;
    $job = new class
    {
        public function fail(Throwable $e): void {}
    };

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue();
});

test('dispatches a fresh setup task and fails the current task when base_version has drifted', function () {
    config()->set('yak.sandbox.base_version', 2);
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $repo = Repository::factory()->create([
        'slug' => 'acme/drifted',
        'setup_status' => 'ready',
        'sandbox_snapshot' => 'yak-tpl-acme-drifted/ready',
        'sandbox_base_version' => 1,
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/drifted']);

    $called = false;
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse();
    expect($job->failed)->toBeTrue();
    expect($job->failMessage)->toContain('v1 → v2');
    expect($job->failMessage)->toContain('fresh Setup task');
});

test('dispatches a setup task when template predates the versioning system (null stored version)', function () {
    config()->set('yak.sandbox.base_version', 2);
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $repo = Repository::factory()->create([
        'slug' => 'acme/legacy',
        'setup_status' => 'ready',
        'sandbox_snapshot' => 'yak-tpl-acme-legacy/ready',
        'sandbox_base_version' => null,
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/legacy']);

    $called = false;
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse();
    expect($job->failed)->toBeTrue();
    expect($job->failMessage)->toContain('legacy (unversioned) → v2');

    Queue::assertPushed(SetupYakJob::class);

    $repo->refresh();
    expect($repo->sandbox_snapshot)->toBeNull();
    expect($repo->sandbox_base_version)->toBeNull();
    expect($repo->setup_status)->toBe('pending');

    Queue::assertPushed(SetupYakJob::class, function (SetupYakJob $dispatched) use ($repo) {
        return $dispatched->task->repo === $repo->slug
            && $dispatched->task->mode === TaskMode::Setup;
    });

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_log)->toContain('Sandbox base image updated');
});

test('lets a task through when sandbox_base_version matches config', function () {
    config()->set('yak.sandbox.base_version', 2);
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);

    Repository::factory()->create([
        'slug' => 'acme/current',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/current']);

    $called = false;
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue();
    expect($job->failed)->toBeFalse();
    Queue::assertNotPushed(SetupYakJob::class);
});

test('dispatches a failure notification when a non-system task is refused', function () {
    Queue::fake();
    Repository::factory()->pendingSetup()->create(['slug' => 'acme/notify-me']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'acme/notify-me',
        'source' => 'slack',
    ]);

    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () {});

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $dispatched) use ($task) {
        return $dispatched->task->id === $task->id
            && $dispatched->type === NotificationType::Error
            && str_contains($dispatched->message, 'has not been set up');
    });
});

test('does not dispatch a failure notification for system-source tasks', function () {
    Queue::fake();
    Repository::factory()->pendingSetup()->create(['slug' => 'acme/system-task']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'acme/system-task',
        'source' => 'system',
    ]);

    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, function () {});

    Queue::assertNotPushed(SendNotificationJob::class);
});

test('all agent-running jobs wire up EnsureRepoReady before the agent runs', function () {
    Repository::factory()->create(['slug' => 'test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo']);

    $jobs = [
        new RunYakJob($task),
        new RetryYakJob($task),
        new ResearchYakJob($task),
        new ClarificationReplyJob($task, 'reply'),
    ];

    foreach ($jobs as $job) {
        $middlewareClasses = array_map(fn ($m) => $m::class, $job->middleware());
        expect($middlewareClasses)->toContain(EnsureRepoReady::class);
    }
});
