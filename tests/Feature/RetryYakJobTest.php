<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RetryYakJob;
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
| Successful Retry
|--------------------------------------------------------------------------
*/

test('retry routes to AwaitingClarification when Claude signals clarificationNeeded', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry_clarify',
        resultSummary: 'Still stuck',
        costUsd: 0.15,
        numTurns: 2,
        durationMs: 5000,
        isError: false,
        clarificationNeeded: true,
        clarificationOptions: ['Approach X', 'Approach Y'],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'retry-clar-repo', 'path' => '/home/yak/repos/retry-clar-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'retry-clar-repo',
        'branch_name' => 'yak/retry-clar',
        'source' => 'linear',
    ]);

    (new RetryYakJob($task, 'CI failed'))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->clarification_options)->toBe(['Approach X', 'Approach Y']);
});

test('retry marks task Success and skips push when no new commits', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry_answered',
        resultSummary: 'Looked at the CI output — flake in the network layer, no code change needed.',
        costUsd: 0.20,
        numTurns: 2,
        durationMs: 6000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = (new FakeSandboxManager)->setCommitCount(0);
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'answered-retry-repo', 'path' => '/home/yak/repos/answered-retry-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'answered-retry-repo',
        'branch_name' => 'yak/ISSUE-200',
        'source' => 'slack',
    ]);

    (new RetryYakJob($task, 'Tests failed'))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_url)->toBeNull()
        ->and($task->completed_at)->not->toBeNull();

    Queue::assertNotPushed(ProcessCIResultJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Result);
});

test('successful retry transitions task to awaiting_ci and force pushes branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry_new',
        resultSummary: 'Fixed the CI failures',
        costUsd: 1.50,
        numTurns: 10,
        durationMs: 90000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'test-repo',
        'session_id' => 'sess_original',
        'branch_name' => 'yak/ISSUE-100',
        'cost_usd' => 2.00,
        'num_turns' => 15,
        'duration_ms' => 120000,
    ]);

    $job = new RetryYakJob($task, 'Tests failed: 2 errors');
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_retry_new')
        ->and($task->result_summary)->toBe('Fixed the CI failures')
        ->and((float) $task->cost_usd)->toBe(3.50)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(210000);

    // Sandbox was created and destroyed
    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

test('refreshes git credential helper immediately before push', function () {
    // Regression: token was baked in at prepareRetryBranch and expired during
    // long agent runs, causing the push to fail with a 401.
    config()->set('yak.channels.github.installation_id', 4242);

    $tokens = ['stale-token', 'fresh-token'];
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')
        ->with(4242)
        ->andReturnUsing(function () use (&$tokens): string {
            return array_shift($tokens) ?? 'fresh-token';
        });

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry_refresh',
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

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
        {
            $this->commands[] = $command;

            return parent::run($containerName, $command, $timeout, $asRoot);
        }
    };
    $this->app->instance(IncusSandboxManager::class, $recorder);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'retry-refresh-repo', 'path' => '/home/yak/repos/retry-refresh-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'retry-refresh-repo',
        'branch_name' => 'yak/ISSUE-42',
    ]);

    (new RetryYakJob($task, 'CI failed'))->handle($fake);

    $credentialCommands = array_keys(array_filter(
        $recorder->commands,
        fn (string $cmd) => str_contains($cmd, 'credential.https://github.com.helper'),
    ));
    $pushIndex = null;
    foreach ($recorder->commands as $i => $cmd) {
        if (str_contains($cmd, 'git push --force-with-lease origin')) {
            $pushIndex = $i;
            break;
        }
    }

    expect($credentialCommands)->toHaveCount(2)
        ->and($pushIndex)->not->toBeNull()
        ->and(max($credentialCommands))->toBeLessThan($pushIndex)
        ->and($recorder->commands[max($credentialCommands)])->toContain('fresh-token');
});

test('successful retry accumulates cost and turns on task', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_2',
        resultSummary: 'Done',
        costUsd: 0.75,
        numTurns: 5,
        durationMs: 30000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'acc-repo', 'path' => '/home/yak/repos/acc-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acc-repo',
        'session_id' => 'sess_1',
        'branch_name' => 'yak/ACC-1',
        'cost_usd' => 3.00,
        'num_turns' => 20,
        'duration_ms' => 150000,
    ]);

    $job = new RetryYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(3.75)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(180000);
});

/*
|--------------------------------------------------------------------------
| Claude --resume Flag — intentionally NOT used on retry
|--------------------------------------------------------------------------
*/

test('does NOT pass --resume on retry (sandbox is fresh, session file is gone)', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_resumed',
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

    $repository = Repository::factory()->create(['slug' => 'res-repo', 'path' => '/home/yak/repos/res-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'res-repo',
        'session_id' => 'sess_original_123',
        'branch_name' => 'yak/RES-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle($fake);

    // session_id is stored on the task but we deliberately don't pass
    // it through — see the comment in RetryYakJob::runRetry().
    expect($fake->lastCall()->resumeSessionId)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Retry Prompt
|--------------------------------------------------------------------------
*/

test('retry prompt includes CI failure output', function () {
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

    $repository = Repository::factory()->create(['slug' => 'pr-repo', 'path' => '/home/yak/repos/pr-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'pr-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/PR-1',
    ]);

    $failureOutput = 'FAIL tests/Feature/AuthTest.php - Expected 200 got 500';

    $job = new RetryYakJob($task, $failureOutput);
    $job->handle($fake);

    expect($fake->lastCall())->not->toBeNull()
        ->and($fake->lastCall()->prompt)->toContain($failureOutput);

    // Retries must NOT use --resume — the previous attempt's sandbox
    // was torn down after the push, so Claude's session history is
    // gone and `claude --resume <id>` would fail with
    // "No conversation found with session ID". See task 4384.
    expect($fake->lastCall()->resumeSessionId)->toBeNull();
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

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'err-repo', 'path' => '/home/yak/repos/err-repo', 'default_branch' => 'main']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'err-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/ERR-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('RetryYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->retrying()->make();
    $job = new RetryYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('RetryYakJob has EnsureDailyBudget middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->retrying()->create(['repo' => 'mw-repo']);

    $job = new RetryYakJob($task);
    $middleware = $job->middleware();
    $classes = array_map(fn ($m) => $m::class, $middleware);

    expect($classes)->toContain(EnsureDailyBudget::class)
        ->and($classes)->toContain(EnsureRepoReady::class);
});

/*
|--------------------------------------------------------------------------
| Sandbox Lifecycle
|--------------------------------------------------------------------------
*/

test('sandbox is destroyed even when retry fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Error',
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

    Repository::factory()->create(['slug' => 'sb-repo', 'path' => '/home/yak/repos/sb-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'sb-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/SB-1',
    ]);

    (new RetryYakJob($task))->handle($fake);

    expect($fakeSandbox->destroyedContainers)->toHaveCount(1);
});
