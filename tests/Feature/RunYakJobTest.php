<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/*
|--------------------------------------------------------------------------
| Successful Run
|--------------------------------------------------------------------------
*/

test('clarification is handled for any source, not just slack', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_clarify',
        resultSummary: 'Need more info',
        costUsd: 0.05,
        numTurns: 1,
        durationMs: 2000,
        isError: false,
        clarificationNeeded: true,
        clarificationOptions: ['Option A', 'Option B'],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'linear-clar-repo', 'path' => '/home/yak/repos/linear-clar-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'linear-clar-repo',
        'source' => 'linear',
    ]);

    (new RunYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->clarification_options)->toBe(['Option A', 'Option B']);
});

test('clarification is handled for sentry tasks too', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_sentry_clarify',
        resultSummary: 'Need trace',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: true,
        clarificationOptions: ['Share a trace ID', 'Close as environment-specific'],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'sentry-repo', 'path' => '/home/yak/repos/sentry-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'sentry-repo',
        'source' => 'sentry',
    ]);

    (new RunYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingClarification);
});

test('handleSuccess marks task Success and skips push when no new commits', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_answered',
        resultSummary: 'The middleware is idempotent; safe to call twice.',
        costUsd: 0.10,
        numTurns: 3,
        durationMs: 4000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = (new FakeSandboxManager)->setCommitCount(0);
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'answered-repo', 'path' => '/home/yak/repos/answered-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'answered-repo', 'source' => 'slack']);

    (new RunYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_url)->toBeNull()
        ->and($task->result_summary)->toBe('The middleware is idempotent; safe to call twice.')
        ->and($task->completed_at)->not->toBeNull();

    Queue::assertNotPushed(ProcessCIResultJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Result);
});

test('successful run transitions task to awaiting_ci and pushes branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_success123',
        resultSummary: 'Fixed the bug successfully',
        costUsd: 2.50,
        numTurns: 15,
        durationMs: 120000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'slack']);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_success123')
        ->and($task->result_summary)->toBe('Fixed the bug successfully')
        ->and((float) $task->cost_usd)->toBe(2.50)
        ->and($task->num_turns)->toBe(15)
        ->and($task->duration_ms)->toBe(120000)
        ->and($task->branch_name)->toStartWith('yak/');

    // Sandbox was created and destroyed
    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

test('successful run notifies source that task is awaiting CI', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_notify',
        resultSummary: 'Fix committed',
        costUsd: 1.0,
        numTurns: 5,
        durationMs: 10000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create([
        'slug' => 'notify-repo',
        'path' => '/home/yak/repos/notify-repo',
        'ci_system' => 'github_actions',
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'notify-repo']);

    (new RunYakJob($task))->handle($fake);

    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Progress && $job->task->id === $task->id);
});

test('task does not transition to awaiting_ci when ci_system is none', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_nociaw',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create([
        'slug' => 'no-ci-status-repo',
        'path' => '/home/yak/repos/no-ci-status-repo',
        'ci_system' => 'none',
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'no-ci-status-repo']);

    (new RunYakJob($task))->handle($fake);

    $task->refresh();

    // For ci_system=none, AwaitingCi is a meaningless transient state —
    // ProcessCIResultJob transitions the task to Success. The task should
    // never end up stranded at AwaitingCi just because PR creation failed.
    expect($task->status)->not->toBe(TaskStatus::AwaitingCi);

    Queue::assertPushed(ProcessCIResultJob::class, fn ($job) => $job->task->id === $task->id && $job->passed === true);
});

test('no awaiting-CI notification dispatched when ci_system is none', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_nocinotify',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create([
        'slug' => 'no-ci-repo',
        'path' => '/home/yak/repos/no-ci-repo',
        'ci_system' => 'none',
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'no-ci-repo']);

    (new RunYakJob($task))->handle($fake);

    // We now emit a "starting work" progress at pickup regardless of
    // CI. Specifically assert that the *CI-waiting* progress is not
    // sent — that's the one that should be skipped when ci_system is none.
    Queue::assertNotPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Progress
            && str_contains($job->message, 'waiting for CI');
    });
});

test('successful run creates branch with yak/{external_id} naming', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'my-repo',
        'external_id' => 'ISSUE-42',
    ]);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->branch_name)->toBe('yak/ISSUE-42');
});

test('branch name gets a counter suffix when remote already has the branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_collision',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    // Pretend `yak/ISSUE-42` and `yak/ISSUE-42-2` already exist on the remote.
    $fakeSandbox = new class(['yak/ISSUE-42', 'yak/ISSUE-42-2']) extends FakeSandboxManager
    {
        /** @param  array<int, string>  $existingRemoteBranches */
        public function __construct(private array $existingRemoteBranches) {}

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false): ProcessResult
        {
            if (preg_match("/git ls-remote --heads origin '([^']+)'/", $command, $m)) {
                return in_array($m[1], $this->existingRemoteBranches, true)
                    ? Process::result("abc123\trefs/heads/{$m[1]}\n")
                    : Process::result('');
            }

            return parent::run($containerName, $command, $timeout);
        }
    };
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'collide-repo', 'path' => '/home/yak/repos/collide-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'collide-repo',
        'external_id' => 'ISSUE-42',
    ]);

    (new RunYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->branch_name)->toBe('yak/ISSUE-42-3');
});

test('successful run increments attempts', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo', 'attempts' => 0]);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();
    expect($task->attempts)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Sandbox Lifecycle
|--------------------------------------------------------------------------
*/

test('sandbox is created and destroyed on successful run', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'sb-repo', 'path' => '/home/yak/repos/sb-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'sb-repo']);

    (new RunYakJob($task))->handle($fake);

    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1)
        ->and($fakeSandbox->createdContainers[0])->toBe($fakeSandbox->destroyedContainers[0]);
});

test('sandbox is destroyed even when agent errors', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Rate limited by API',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'err-repo', 'path' => '/home/yak/repos/err-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'err-repo']);

    (new RunYakJob($task))->handle($fake);

    expect($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Clarification Detection
|--------------------------------------------------------------------------
*/

test('clarification from slack source sets awaiting_clarification status', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_clarify',
        resultSummary: '',
        costUsd: 0.75,
        numTurns: 5,
        durationMs: 30000,
        isError: false,
        clarificationNeeded: true,
        clarificationOptions: ['Fix the auth flow', 'Fix the API endpoint', 'Both'],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo', 'source' => 'slack']);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->session_id)->toBe('sess_clarify')
        ->and($task->clarification_options)->toBe(['Fix the auth flow', 'Fix the API endpoint', 'Both'])
        ->and($task->clarification_expires_at)->not->toBeNull()
        ->and((float) $task->cost_usd)->toBe(0.75)
        ->and($task->num_turns)->toBe(5);
});

test('clarification from non-slack source is honored and routes to AwaitingClarification', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_linear_clarify',
        resultSummary: '',
        costUsd: 0.50,
        numTurns: 3,
        durationMs: 15000,
        isError: false,
        clarificationNeeded: true,
        clarificationOptions: ['Option A'],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo', 'source' => 'linear']);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification);
});

/*
|--------------------------------------------------------------------------
| Claude Error
|--------------------------------------------------------------------------
*/

test('claude error response marks task as failed', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Rate limited by API',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo']);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();
});

test('malformed claude output marks task as failed', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: '',
        resultSummary: 'Agent returned an error or malformed output',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: 'not json at all {{',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo']);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Prompt Assembly
|--------------------------------------------------------------------------
*/

test('assembles prompt based on task source', function () {
    $sources = ['slack', 'linear', 'sentry', 'flaky-test', 'manual'];

    foreach ($sources as $source) {
        $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
            sessionId: 'sess_prompt',
            resultSummary: 'Done',
            costUsd: 0.0,
            numTurns: 1,
            durationMs: 1000,
            isError: false,
            clarificationNeeded: false,
            clarificationOptions: [],
            rawOutput: '{}',
        ));
        $this->app->instance(AgentRunner::class, $fake);

        $fakeSandbox = new FakeSandboxManager;
        $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

        Process::fake([
            '*' => Process::result(''),
        ]);

        $repository = Repository::factory()->create(['path' => '/home/yak/repos/repo-' . $source]);
        $task = YakTask::factory()->pending()->create([
            'repo' => $repository->slug,
            'source' => $source,
            'description' => 'Test description for ' . $source,
        ]);

        $job = new RunYakJob($task);
        $job->handle($fake);

        expect($fake->lastCall())->not->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('RunYakJob fails gracefully when repo is unknown', function () {
    Repository::factory()->create(['slug' => 'repo-a', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'repo-b', 'is_active' => true]);

    $task = YakTask::factory()->pending()->create(['repo' => 'unknown']);

    $fake = new FakeAgentRunner;
    (new RunYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_log)->toContain('Could not determine which repo');
    expect($task->error_log)->toContain('repo-a');
    expect($task->error_log)->toContain('repo-b');
});

test('RunYakJob fails gracefully when repo slug does not exist', function () {
    Repository::factory()->create(['slug' => 'actual-repo', 'is_active' => true]);

    $task = YakTask::factory()->pending()->create(['repo' => 'non-existent-repo']);

    $fake = new FakeAgentRunner;
    (new RunYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_log)->toContain("'non-existent-repo' not found");
});

test('RunYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->pending()->make();
    $job = new RunYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('RunYakJob has EnsureDailyBudget middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'mw-repo']);

    $job = new RunYakJob($task);
    $middleware = $job->middleware();
    $classes = array_map(fn ($m) => $m::class, $middleware);

    expect($classes)->toContain(EnsureDailyBudget::class)
        ->and($classes)->toContain(EnsureRepoReady::class);
});

/*
|--------------------------------------------------------------------------
| Start-of-work progress notification
|--------------------------------------------------------------------------
*/

test('emits a Progress notification at pickup on the first attempt', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_startprog',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'progress-repo', 'path' => '/home/yak/repos/progress-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'progress-repo', 'attempts' => 0]);

    (new RunYakJob($task))->handle($fake);

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($task) {
        return $job->task->id === $task->id
            && $job->type === NotificationType::Progress
            && str_contains($job->message, 'exploring the codebase');
    });
});

test('skips start-of-work progress notification when emit_start_progress is disabled', function () {
    config()->set('yak.emit_start_progress', false);
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_nostart',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'quiet-repo', 'path' => '/home/yak/repos/quiet-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'quiet-repo', 'attempts' => 0]);

    (new RunYakJob($task))->handle($fake);

    Queue::assertNotPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Progress
            && str_contains($job->message, 'exploring the codebase');
    });
});

test('refreshes git credential helper immediately before push', function () {
    // Regression: token was baked in at prepareBranch and expired during long
    // agent runs, causing the push to fail with a 401. The helper must be
    // reconfigured right before push so the cache-refresh kicks in.
    config()->set('yak.channels.github.installation_id', 4242);

    $tokens = ['stale-token', 'fresh-token'];
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')
        ->with(4242)
        ->andReturnUsing(function () use (&$tokens): string {
            return array_shift($tokens) ?? 'fresh-token';
        });

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_refresh',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $recorder = new class extends FakeSandboxManager
    {
        /** @var array<int, string> */
        public array $commands = [];

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false): ProcessResult
        {
            $this->commands[] = $command;

            return parent::run($containerName, $command, $timeout, $asRoot);
        }
    };
    $this->app->instance(IncusSandboxManager::class, $recorder);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'refresh-repo', 'path' => '/home/yak/repos/refresh-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'refresh-repo']);

    (new RunYakJob($task))->handle($fake);

    $credentialCommands = array_keys(array_filter(
        $recorder->commands,
        fn (string $cmd) => str_contains($cmd, 'credential.https://github.com.helper'),
    ));
    $pushIndex = null;
    foreach ($recorder->commands as $i => $cmd) {
        if (str_contains($cmd, 'git push origin')) {
            $pushIndex = $i;
            break;
        }
    }

    expect($credentialCommands)->toHaveCount(2)
        ->and($pushIndex)->not->toBeNull()
        ->and(max($credentialCommands))->toBeLessThan($pushIndex)
        ->and($recorder->commands[max($credentialCommands)])->toContain('fresh-token');
});

test('does not emit start-of-work progress on retry (attempts > 0)', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));

    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'retry-repo', 'path' => '/home/yak/repos/retry-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'retry-repo', 'attempts' => 1]);

    (new RunYakJob($task))->handle($fake);

    Queue::assertNotPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Progress
            && str_contains($job->message, 'exploring the codebase');
    });
});
