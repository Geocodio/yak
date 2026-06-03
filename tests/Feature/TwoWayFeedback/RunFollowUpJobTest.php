<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

function fakeFollowUpResult(string $sessionId = 'sess_followup'): AgentRunResult
{
    return new AgentRunResult(
        sessionId: $sessionId,
        resultSummary: 'Addressed the feedback',
        costUsd: 0.50,
        numTurns: 4,
        durationMs: 20000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    );
}

test('RunFollowUpJob resumes the session, force-pushes the existing branch, and parks at awaiting_ci', function () {
    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);

    $pushed = false;
    $recorder = new class($pushed) extends FakeSandboxManager
    {
        public function __construct(public bool &$pushed) {}

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
        {
            if (str_contains($command, 'git rev-parse --abbrev-ref HEAD')) {
                return Process::result('yak/CSV-1');
            }

            if (str_contains($command, 'git push --force-with-lease')) {
                $this->pushed = true;

                return Process::result('');
            }

            return parent::run($containerName, $command, $timeout, $asRoot);
        }
    };
    $this->app->instance(IncusSandboxManager::class, $recorder);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'fu-repo', 'path' => '/home/yak/repos/fu-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'fu-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/CSV-1',
        'pr_url' => 'https://github.com/acme/fu-repo/pull/9',
        'pr_number' => 9,
        'description' => 'Also handle the empty-state',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_followup')
        ->and($task->result_summary)->toBe('Addressed the feedback')
        ->and($pushed)->toBeTrue()
        ->and($fake->lastCall()->resumeSessionId)->toBe('sess_parent')
        ->and($fake->lastCall()->prompt)->toContain('Also handle the empty-state');
});

test('RunFollowUpJob never creates a new branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);

    $recorder = new class extends FakeSandboxManager
    {
        /** @var array<int, string> */
        public array $commands = [];

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
        {
            $this->commands[] = $command;

            return parent::run($containerName, $command, $timeout, $asRoot);
        }
    };
    $this->app->instance(IncusSandboxManager::class, $recorder);
    Process::fake(['*git rev-parse *' => Process::result(output: 'yak/CSV-2'), '*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'nb-repo', 'path' => '/home/yak/repos/nb-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'nb-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/CSV-2',
        'pr_url' => 'https://github.com/acme/nb-repo/pull/3',
        'description' => 'tweak',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    $createdBranch = array_filter($recorder->commands, fn (string $c) => str_contains($c, 'checkout -b'));
    expect($createdBranch)->toBeEmpty();
});

test('RunFollowUpJob dispatches to the yak-claude queue', function () {
    $task = YakTask::factory()->make(['branch_name' => 'yak/Q-1']);

    expect((new RunFollowUpJob($task))->queue)->toBe('yak-claude');
});

test('RunFollowUpJob with ci_system=none dispatches ProcessCIResultJob instead of waiting for CI', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*git rev-parse *' => Process::result(output: 'yak/NOCI-1'), '*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'noci-repo', 'path' => '/home/yak/repos/noci-repo', 'ci_system' => 'none']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'noci-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/NOCI-1',
        'pr_url' => 'https://github.com/acme/noci-repo/pull/5',
        'description' => 'tweak',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->task->id === $task->id);
    Queue::assertNotPushed(SendNotificationJob::class, fn ($job) => str_contains((string) ($job->message ?? ''), 'waiting for CI'));
});

test('RunFollowUpJob fails when the task has no branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'nobranch-repo', 'path' => '/home/yak/repos/nobranch-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'nobranch-repo',
        'branch_name' => null,
        'pr_url' => 'https://github.com/acme/nobranch-repo/pull/1',
        'description' => 'tweak',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});
