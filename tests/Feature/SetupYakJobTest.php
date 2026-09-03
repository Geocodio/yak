<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\YakPromptBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/*
|--------------------------------------------------------------------------
| Successful Setup
|--------------------------------------------------------------------------
*/

test('successful setup transitions task to success and repo to ready', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_setup_ok',
        resultSummary: 'Repository environment set up successfully',
        costUsd: 3.00,
        numTurns: 20,
        durationMs: 180000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'test-repo',
        'path' => '/home/yak/repos/test-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    $job = new SetupYakJob($task);
    $job->handle($fake);

    $task->refresh();
    $repository->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->session_id)->toBe('sess_setup_ok')
        ->and($task->result_summary)->toBe('Repository environment set up successfully')
        ->and((float) $task->cost_usd)->toBe(3.00)
        ->and($task->num_turns)->toBe(20)
        ->and($task->duration_ms)->toBe(180000)
        ->and($task->completed_at)->not->toBeNull()
        ->and($repository->setup_status)->toBe('ready')
        ->and($repository->sandbox_snapshot)->not->toBeNull();
});

test('re-running setup destroys the existing template first so the clone starts from yak-base', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_resetup',
        resultSummary: 'Reconfigured',
        costUsd: 1.0,
        numTurns: 5,
        durationMs: 5000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'already-set-up',
        'sandbox_snapshot' => 'yak-tpl-already-set-up/ready',
        'sandbox_base_version' => null,
        'setup_status' => 'ready',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'already-set-up',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    (new SetupYakJob($task))->handle($fake);

    expect($fakeSandbox->invalidatedTemplates)->toContain('yak-tpl-already-set-up');
});

test('first-time setup skips invalidation (no existing template)', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_first',
        resultSummary: 'Set up',
        costUsd: 1.0,
        numTurns: 5,
        durationMs: 5000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->pendingSetup()->create(['slug' => 'fresh']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'fresh',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    (new SetupYakJob($task))->handle($fake);

    expect($fakeSandbox->invalidatedTemplates)->toBeEmpty();
});

test('setup promotes sandbox to repo template on success', function () {
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

    $repository = Repository::factory()->create([
        'slug' => 'tpl-repo',
        'path' => '/home/yak/repos/tpl-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'tpl-repo',
        'mode' => TaskMode::Setup,
    ]);

    $job = new SetupYakJob($task);
    $job->handle($fake);

    expect($fakeSandbox->promotedTemplates)->toHaveCount(1)
        ->and($fakeSandbox->promotedTemplates[0])->toContain('tpl-repo');
});

test('setup transitions repo setup_status through running to ready on success', function () {
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

    $repository = Repository::factory()->create([
        'slug' => 'run-repo',
        'path' => '/home/yak/repos/run-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'run-repo',
        'mode' => TaskMode::Setup,
    ]);

    $capturedStatuses = [];
    Repository::updating(function ($model) use (&$capturedStatuses) {
        if ($model->isDirty('setup_status')) {
            $capturedStatuses[] = $model->setup_status;
        }
    });

    $job = new SetupYakJob($task);
    $job->handle($fake);

    expect($capturedStatuses)->toContain('running')
        ->toContain('ready');
});

test('setup increments attempts', function () {
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

    $repository = Repository::factory()->create([
        'slug' => 'att-repo',
        'path' => '/home/yak/repos/att-repo',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'att-repo',
        'mode' => TaskMode::Setup,
        'attempts' => 0,
    ]);

    $job = new SetupYakJob($task);
    $job->handle($fake);

    $task->refresh();
    expect($task->attempts)->toBe(1);
});

test('agent budget is job timeout minus elapsed pre-agent time minus shutdown buffer', function () {
    // A fixed "$timeout - 30" ignores time spent on sandbox create + clone
    // before the agent starts, so the agent's graceful deadline lands at the
    // same instant as the worker's pcntl hard kill — and the hard kill wins,
    // discarding an otherwise-complete setup.
    $this->travelTo(now());

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_budget',
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

    // Sandbox creation burns 600s of the job budget before the agent starts.
    $fakeSandbox = new class extends FakeSandboxManager
    {
        public function create(YakTask $task, Repository $repository): string
        {
            Carbon::setTestNow(now()->addSeconds(600));

            return parent::create($task, $repository);
        }
    };
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'slow-repo', 'path' => '/home/yak/repos/slow-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'slow-repo', 'mode' => TaskMode::Setup]);

    (new SetupYakJob($task))->handle($fake);

    // 7200 (job timeout) - 600 (elapsed) - 180 (shutdown buffer)
    expect($fake->lastCall()->timeoutSeconds)->toBe(6420);
});

/*
|--------------------------------------------------------------------------
| Sandbox Lifecycle
|--------------------------------------------------------------------------
*/

test('sandbox is created and destroyed on setup', function () {
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
    $task = YakTask::factory()->pending()->create(['repo' => 'sb-repo', 'mode' => TaskMode::Setup]);

    (new SetupYakJob($task))->handle($fake);

    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

test('sandbox is destroyed even when setup fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Docker compose failed to start',
        costUsd: 0.25,
        numTurns: 1,
        durationMs: 5000,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'err-repo', 'path' => '/home/yak/repos/err-repo', 'setup_status' => 'pending']);
    $task = YakTask::factory()->pending()->create(['repo' => 'err-repo', 'mode' => TaskMode::Setup]);

    (new SetupYakJob($task))->handle($fake);

    expect($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

test('claude error marks task failed and repo setup_status failed', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Docker compose failed to start',
        costUsd: 0.25,
        numTurns: 1,
        durationMs: 5000,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'err-repo',
        'path' => '/home/yak/repos/err-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'err-repo',
        'mode' => TaskMode::Setup,
    ]);

    $job = new SetupYakJob($task);
    $job->handle($fake);

    $task->refresh();
    $repository->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Docker compose failed to start')
        ->and($task->completed_at)->not->toBeNull()
        ->and($repository->setup_status)->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| Queue Configuration
|--------------------------------------------------------------------------
*/

test('SetupYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->pending()->make();
    $job = new SetupYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('SetupYakJob has PausesDuringDrain, HoldsForClaudeAuth, ClaimsTaskAtomically, and EnsureDailyBudget middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'mw-repo']);

    $job = new SetupYakJob($task);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(4)
        ->and($middleware[0])->toBeInstanceOf(PausesDuringDrain::class)
        ->and($middleware[1])->toBeInstanceOf(HoldsForClaudeAuth::class)
        ->and($middleware[2])->toBeInstanceOf(ClaimsTaskAtomically::class)
        ->and($middleware[3])->toBeInstanceOf(EnsureDailyBudget::class);
});

/*
|--------------------------------------------------------------------------
| Hard-kill retry guard
|--------------------------------------------------------------------------
*/

test('a hard-killed retry fails outright once the agent already ran for this task', function () {
    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'hardkill-repo', 'path' => '/home/yak/repos/hardkill-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'hardkill-repo',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    // Simulate a previous attempt that reached the agent before a worker
    // timeout/SIGKILL/OOM hard-killed it without ever calling failed().
    TaskLogger::info($task, 'Starting Claude agent');

    $fake = new FakeAgentRunner;
    $this->app->instance(AgentRunner::class, $fake);
    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    $job = new SetupYakJob($task);
    $job->job = new class
    {
        public function attempts(): int
        {
            return 2;
        }
    };

    $job->handle($fake);

    $task->refresh();
    $repository->refresh();

    expect($task->status)->toBe(TaskStatus::Failed);
    expect($repository->setup_status)->toBe('failed');
    expect($fake->calls)->toBeEmpty();
    expect($fakeSandbox->createdContainers)->toBeEmpty();
});

test('a first attempt runs normally even though tries is 1', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_first',
        resultSummary: 'ok',
        costUsd: 1.0,
        numTurns: 5,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'first-attempt-repo', 'path' => '/home/yak/repos/first-attempt-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'first-attempt-repo',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    $job = new SetupYakJob($task);
    $job->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success);
    expect($fake->calls)->toHaveCount(1);
});

test('a retry with no prior agent run proceeds normally (e.g. release from a middleware hold)', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry',
        resultSummary: 'ok',
        costUsd: 1.0,
        numTurns: 5,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'held-retry-repo', 'path' => '/home/yak/repos/held-retry-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'held-retry-repo',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    $job = new SetupYakJob($task);
    $job->job = new class
    {
        public function attempts(): int
        {
            return 2;
        }
    };

    $job->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success);
    expect($fake->calls)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Dashboard dispatch
|--------------------------------------------------------------------------
*/

test('creating a repo dispatches SetupYakJob', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create());

    $this->post(route('repos.store'), [
        'name' => 'New Repo',
        'git_url' => 'https://github.com/acme/new-repo.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ])->assertRedirect();

    Queue::assertPushed(SetupYakJob::class, function ($job) {
        return $job->task->mode === TaskMode::Setup
            && $job->task->repo === 'new-repo';
    });
});

test('rerun setup dispatches SetupYakJob', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create([
        'slug' => 'rerun-repo',
        'path' => '/home/yak/repos/rerun-repo',
        'setup_status' => 'failed',
    ]);

    $this->post(route('repos.rerun-setup', $repository))->assertRedirect();

    Queue::assertPushed(SetupYakJob::class, function ($job) {
        return $job->task->repo === 'rerun-repo';
    });
});

/*
|--------------------------------------------------------------------------
| Artisan Command
|--------------------------------------------------------------------------
*/

test('yak:setup-repo command dispatches SetupYakJob', function () {
    Queue::fake();

    $repository = Repository::factory()->create([
        'slug' => 'cmd-repo',
        'name' => 'Command Repo',
        'path' => '/home/yak/repos/cmd-repo',
    ]);

    $this->artisan('yak:setup-repo', ['slug' => 'cmd-repo'])
        ->assertSuccessful();

    Queue::assertPushed(SetupYakJob::class, function ($job) {
        return $job->task->repo === 'cmd-repo'
            && $job->task->mode === TaskMode::Setup;
    });

    $repository->refresh();
    expect($repository->setup_status)->toBe('pending')
        ->and($repository->setup_task_id)->not->toBeNull();
});

test('yak:setup-repo command fails for unknown repo', function () {
    $this->artisan('yak:setup-repo', ['slug' => 'nonexistent-repo'])
        ->assertFailed();
});

/*
|--------------------------------------------------------------------------
| Prompt Template
|--------------------------------------------------------------------------
*/

test('setup prompt template includes repo name and setup steps', function () {
    $prompt = YakPromptBuilder::setupPrompt('My Project');

    expect($prompt)
        ->toContain('My Project')
        ->toContain('docker-compose up -d')
        ->toContain('Install dependencies')
        ->toContain('Run database migrations')
        ->toContain('Do NOT make any code changes');
});

test('taskPrompt routes setup mode to setup template', function () {
    $task = YakTask::factory()->pending()->make([
        'mode' => TaskMode::Setup,
        'repo' => 'test-repo',
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task, ['repo_name' => 'Test Repo']);

    expect($prompt)
        ->toContain('Test Repo')
        ->toContain('Set up the development environment');
});
