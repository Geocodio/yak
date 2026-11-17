<?php

use App\Enums\NotificationType;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\SetupScriptHash;
use Illuminate\Support\Facades\Http;
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
            && $dispatched->type === NotificationType::Retry
            && $dispatched->message === $task->fresh()->stop_summary;
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
    ];

    foreach ($jobs as $job) {
        $middlewareClasses = array_map(fn ($m) => $m::class, $job->middleware());
        expect($middlewareClasses)->toContain(EnsureRepoReady::class);
    }
});

test('a refused agent job notifies the source exactly once', function () {
    Queue::fake([SendNotificationJob::class]);
    Repository::factory()->pendingSetup()->create(['slug' => 'acme/no-snapshot']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/no-snapshot', 'source' => 'linear']);
    $job = new RunYakJob($task);

    (new EnsureRepoReady)->handle($job, fn () => null);
    $job->failed(new RuntimeException('refused'));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

function fakeSetupScriptOnGitHub(string $slug, ?string $contents): void
{
    config()->set('yak.channels.github.installation_id', 12345);
    GitHubInstallationToken::create(['installation_id' => 12345, 'token' => 'ghs_fake', 'expires_at' => now()->addHour()]);
    Http::fake([
        "https://api.github.com/repos/{$slug}/contents/.yak/setup.sh*" => $contents === null
            ? Http::response(['message' => 'Not Found'], 404)
            : Http::response($contents, 200, ['Content-Length' => (string) strlen($contents)]),
    ]);
}

test('a changed setup script rebuilds the template', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    fakeSetupScriptOnGitHub('acme/scripted', "composer install\n");
    $repo = Repository::factory()->create([
        'slug' => 'acme/scripted',
        'github_full_name' => 'acme/scripted',
        'sandbox_base_version' => (int) config('yak.sandbox.base_version'),
        'setup_script_hash' => SetupScriptHash::forContents("composer install --old\n"),
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/scripted']);
    $job = makeTestJobDouble();
    $job->task = $task;
    $called = false;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($job->failMessage)->toContain('.yak/setup.sh changed')->toContain('fresh Setup task')
        ->and($repo->fresh()->setup_status)->toBe('pending');
});

test('an unchanged or missing setup script does not', function (?string $remote) {
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    fakeSetupScriptOnGitHub('acme/scripted', $remote);
    Repository::factory()->create([
        'slug' => 'acme/scripted',
        'github_full_name' => 'acme/scripted',
        'sandbox_base_version' => (int) config('yak.sandbox.base_version'),
        'setup_script_hash' => SetupScriptHash::forContents("composer install\n"),
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/scripted']);
    $job = makeTestJobDouble();
    $job->task = $task;
    $called = false;

    (new EnsureRepoReady)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue()->and($job->failed)->toBeFalse();
})->with([
    'unchanged' => ["composer install\n"],
    'missing' => [null],
]);

test('waits for setup instead of failing when the base image changed', function () {
    config()->set('yak.sandbox.base_version', 2);
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    Repository::factory()->create([
        'slug' => 'acme/stale',
        'setup_status' => 'ready',
        'sandbox_snapshot' => 'yak-tpl-acme-stale/ready',
        'sandbox_base_version' => 1,
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/stale', 'source' => 'slack']);
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, fn () => null);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->resume_after_setup_at)->not->toBeNull()
        ->and($task->stop_kind)->toBe(StopKind::YakRetrying);
    Queue::assertPushed(SetupYakJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $dispatched) => $dispatched->type === NotificationType::Retry);
});

test('dispatches setup for a repository that was never set up and waits', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/never', 'setup_status' => 'failed', 'sandbox_snapshot' => null]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/never', 'source' => 'slack']);
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, fn () => null);

    Queue::assertPushed(SetupYakJob::class, 1);
    expect($task->fresh()->resume_after_setup_at)->not->toBeNull()
        ->and($job->failed)->toBeTrue();
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('does not dispatch a second setup when one is already running', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/busy', 'setup_status' => 'running', 'sandbox_snapshot' => null]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/busy', 'source' => 'slack']);
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, fn () => null);

    Queue::assertNotPushed(SetupYakJob::class);
    expect($task->fresh()->resume_after_setup_at)->not->toBeNull();
});

test('a parked agent job notifies the source exactly once', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/parked', 'setup_status' => 'running', 'sandbox_snapshot' => null]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/parked', 'source' => 'linear']);
    $job = new RunYakJob($task);

    (new EnsureRepoReady)->handle($job, fn () => null);
    $job->failed(new RuntimeException('refused'));

    expect($task->fresh()->stop_kind)->toBe(StopKind::YakRetrying);
    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $dispatched) => $dispatched->type === NotificationType::Retry);
});

test('a thread task on a repository that is not set up is refused, not parked', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/threaded', 'setup_status' => 'failed', 'sandbox_snapshot' => null]);
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/threaded', 'source' => 'slack', 'thread_id' => $thread->id]);
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, fn () => null);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->resume_after_setup_at)->toBeNull()
        ->and($task->stop_kind)->toBe(StopKind::YakStopped)
        ->and($job->failed)->toBeTrue();
    Queue::assertNotPushed(SetupYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('a task the state machine will not fail is refused, not parked', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/stuck', 'setup_status' => 'failed', 'sandbox_snapshot' => null]);
    $task = YakTask::factory()->create(['repo' => 'acme/stuck', 'source' => 'slack', 'status' => TaskStatus::Cancelled]);
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, fn () => null);

    expect($task->fresh()->resume_after_setup_at)->toBeNull()
        ->and($task->fresh()->stop_kind)->toBe(StopKind::YakStopped);
    Queue::assertNotPushed(SetupYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('a changed setup script parks the task and dispatches one setup', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    fakeSetupScriptOnGitHub('acme/scripted-park', "composer install\n");
    Repository::factory()->create([
        'slug' => 'acme/scripted-park',
        'github_full_name' => 'acme/scripted-park',
        'sandbox_base_version' => (int) config('yak.sandbox.base_version'),
        'setup_script_hash' => SetupScriptHash::forContents("composer install --old\n"),
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/scripted-park', 'source' => 'slack']);
    $job = makeTestJobDouble();
    $job->task = $task;

    (new EnsureRepoReady)->handle($job, fn () => null);

    Queue::assertPushed(SetupYakJob::class, 1);
    expect($task->fresh()->resume_after_setup_at)->not->toBeNull()
        ->and($task->fresh()->stop_kind)->toBe(StopKind::YakRetrying);
});
