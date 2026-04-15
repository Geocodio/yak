<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
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

    Queue::assertNotPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Progress);
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

        public function run(string $containerName, string $command, ?int $timeout = null): ProcessResult
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

test('clarification from non-slack source is treated as success', function () {
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

    expect($task->status)->toBe(TaskStatus::AwaitingCi);
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
