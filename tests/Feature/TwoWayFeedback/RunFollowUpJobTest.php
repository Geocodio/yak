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

test('RunFollowUpJob stores the change summary and the rewritten description separately', function () {
    $output = "## What changed in this run\n\n- Added backoff\n\n## PR description\n\n## Summary\n\nWhole PR, rewritten.";
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_followup',
        resultSummary: $output,
        costUsd: 0.50,
        numTurns: 4,
        durationMs: 20000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
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

    Queue::fake([ProcessCIResultJob::class, SendNotificationJob::class]);
    (new RunFollowUpJob($task))->handle($fake);

    $task->refresh();
    expect($task->result_summary)->toBe('- Added backoff')
        ->and($task->pr_body_update)->toBe("## Summary\n\nWhole PR, rewritten.");
});

test('RunFollowUpJob leaves pr_body_update null when the description is unchanged', function () {
    $output = "## What changed in this run\n\n- Fixed typo\n\n## PR description\n\nUnchanged.";
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_followup',
        resultSummary: $output,
        costUsd: 0.50,
        numTurns: 4,
        durationMs: 20000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
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

    Queue::fake([ProcessCIResultJob::class, SendNotificationJob::class]);
    (new RunFollowUpJob($task))->handle($fake);

    $task->refresh();
    expect($task->result_summary)->toBe('- Fixed typo')
        ->and($task->pr_body_update)->toBeNull();
});

test('RunFollowUpJob stores a null result_summary instead of an empty string when the changes section is blank', function () {
    $output = "## What changed in this run\n\n   \n\n## PR description\n\nUnchanged.";
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_followup',
        resultSummary: $output,
        costUsd: 0.50,
        numTurns: 4,
        durationMs: 20000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $recorder = new class extends FakeSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
        {
            if (str_contains($command, 'git rev-parse --abbrev-ref HEAD')) {
                return Process::result('yak/CSV-1');
            }

            if (str_contains($command, 'git push --force-with-lease')) {
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

    Queue::fake([ProcessCIResultJob::class, SendNotificationJob::class]);
    (new RunFollowUpJob($task))->handle($fake);

    $task->refresh();
    expect($task->result_summary)->toBeNull();
});

test('RunFollowUpJob never creates a new branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);

    $recorder = new FakeSandboxManager;
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

test('RunFollowUpJob retries without --resume when the session transcript is gone', function () {
    $staleResume = AgentRunResult::failure('', '')->withStderr(
        'No conversation found with session ID: sess_parent',
    );

    $fake = (new FakeAgentRunner)
        ->queueResult($staleResume)
        ->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*git rev-parse *' => Process::result(output: 'yak/STALE-1'), '*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'stale-repo', 'path' => '/home/yak/repos/stale-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'stale-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/STALE-1',
        'pr_url' => 'https://github.com/acme/stale-repo/pull/7',
        'description' => 'tweak',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    expect($fake->calls)->toHaveCount(2)
        ->and($fake->calls[0]->resumeSessionId)->toBe('sess_parent')
        ->and($fake->calls[1]->resumeSessionId)->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
});

test('RunFollowUpJob skips the push and resolves immediately when there are no new commits', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);

    $sandbox = (new FakeSandboxManager)->setCommitCount(0);
    $this->app->instance(IncusSandboxManager::class, $sandbox);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'noop-repo', 'path' => '/home/yak/repos/noop-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'noop-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/NOOP-1',
        'pr_url' => 'https://github.com/acme/noop-repo/pull/12',
        'description' => 'is this thread-safe?',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    $pushCommands = array_filter($sandbox->commands, fn (string $c) => str_contains($c, 'git push'));

    expect($pushCommands)->toBeEmpty()
        ->and($task->fresh()->status)->not->toBe(TaskStatus::AwaitingCi);

    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->task->id === $task->id && $job->passed === true);
});

test('RunFollowUpJob pushes and awaits CI when there are new commits', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult());
    $this->app->instance(AgentRunner::class, $fake);

    $sandbox = (new FakeSandboxManager)->setCommitCount(2);
    $this->app->instance(IncusSandboxManager::class, $sandbox);
    Process::fake(['*git rev-parse *' => Process::result(output: 'yak/HASCOMMITS-1'), '*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'hascommits-repo', 'path' => '/home/yak/repos/hascommits-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'hascommits-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/HASCOMMITS-1',
        'pr_url' => 'https://github.com/acme/hascommits-repo/pull/13',
        'description' => 'add the missing test',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    $pushCommands = array_filter($sandbox->commands, fn (string $c) => str_contains($c, 'git push'));

    expect($pushCommands)->not->toBeEmpty()
        ->and($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);

    Queue::assertNotPushed(ProcessCIResultJob::class);
});

test('RunFollowUpJob surfaces CLI stderr in error_log when the run fails', function () {
    $failure = new AgentRunResult(
        sessionId: '',
        resultSummary: '',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
        errorSubtype: 'error_during_execution',
        stderr: 'MCP server crashed on startup',
    );

    $fake = (new FakeAgentRunner)->queueResult($failure);
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'stderr-repo', 'path' => '/home/yak/repos/stderr-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'stderr-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/STDERR-1',
        'pr_url' => 'https://github.com/acme/stderr-repo/pull/8',
        'description' => 'tweak',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->error_log)->toContain('MCP server crashed on startup');
});

test('RunFollowUpJob restores the parent transcript into the sandbox and persists the new one', function () {
    $fake = (new FakeAgentRunner)->queueResult(fakeFollowUpResult('sess_followup_new'));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);
    Process::fake(['*git rev-parse *' => Process::result(output: 'yak/TR-1'), '*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'tr-repo', 'path' => '/home/yak/repos/tr-repo']);
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'tr-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/TR-1',
        'pr_url' => 'https://github.com/acme/tr-repo/pull/11',
        'description' => 'tweak',
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    expect($fakeSandbox->pushedTranscripts)->toBe(['sess_parent'])
        ->and($fakeSandbox->pulledTranscripts)->toBe(['sess_followup_new']);
});
