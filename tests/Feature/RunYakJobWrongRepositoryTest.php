<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\RepoClarificationResolver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

function wrongRepositoryResult(?string $suggested): AgentRunResult
{
    return new AgentRunResult(
        sessionId: 'sess_wrong_repo',
        resultSummary: 'This issue belongs to a different repository.',
        costUsd: 0.12,
        numTurns: 3,
        durationMs: 4000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
        wrongRepository: true,
        wrongRepositoryReason: 'The billing code lives elsewhere.',
        suggestedRepository: $suggested,
    );
}

/**
 * A sandbox whose working tree is dirty and has no commits, the state that
 * used to fail the run with "Agent finished with uncommitted changes".
 */
function dirtySandbox(): FakeSandboxManager
{
    return new class extends FakeSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
        {
            parent::run($containerName, $command, $timeout, $asRoot, $input, $output);

            if (str_contains($command, 'git status --porcelain')) {
                return Process::result(' M app/Example.php');
            }

            if (str_contains($command, 'git rev-list --count')) {
                return Process::result('0');
            }

            return Process::result('');
        }
    };
}

test('a wrong repository verdict asks for a repo instead of failing on a dirty tree', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(wrongRepositoryResult('acme/billing'));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, dirtySandbox());
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/billing']);
    Repository::factory()->create(['slug' => 'acme/inactive', 'is_active' => false]);

    $task = YakTask::factory()->pending()->create([
        'repo' => 'acme/atlas',
        'source' => 'linear',
        'session_id' => 'old-session',
        'branch_name' => 'yak/old-branch',
    ]);

    (new RunYakJob($task))->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->repo)->toBe('unknown')
        ->and($task->session_id)->toBeNull()
        ->and($task->branch_name)->toBeNull()
        ->and($task->error_log)->toBeNull()
        ->and($task->clarification_options)->toBe(['acme/billing', 'acme/api'])
        ->and($task->clarification_expires_at)->not->toBeNull()
        ->and(RepoClarificationResolver::awaitingRepoChoice($task))->toBeTrue();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Clarification
        && str_contains($job->message, 'I looked in acme/atlas')
        && str_contains($job->message, 'The billing code lives elsewhere.')
        && str_contains($job->message, "1. acme/billing\n2. acme/api"));
    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});

test('an unknown suggested repository is ignored and the others stay alphabetical', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(wrongRepositoryResult('acme/does-not-exist'));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/zeta']);
    Repository::factory()->create(['slug' => 'acme/api']);

    $task = YakTask::factory()->pending()->create(['repo' => 'acme/atlas', 'source' => 'linear']);

    (new RunYakJob($task))->handle($fake);

    expect($task->refresh()->clarification_options)->toBe(['acme/api', 'acme/zeta']);
});

test('a retry that reports the wrong repository asks for a repo and starts over', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(wrongRepositoryResult('acme/billing'));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, dirtySandbox());
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/billing']);

    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acme/atlas',
        'source' => 'linear',
        'session_id' => 'old-session',
        'branch_name' => 'yak/old-branch',
    ]);

    (new RetryYakJob($task, 'ci output'))->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->repo)->toBe('unknown')
        ->and($task->session_id)->toBeNull()
        ->and($task->branch_name)->toBeNull()
        ->and($task->error_log)->toBeNull()
        ->and($task->clarification_options)->toBe(['acme/billing'])
        ->and(RepoClarificationResolver::awaitingRepoChoice($task))->toBeTrue();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Clarification);
    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});

test('a clarification reply that reports the wrong repository asks for a repo and starts over', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(wrongRepositoryResult(null));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, dirtySandbox());
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/api']);

    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'acme/atlas',
        'source' => 'linear',
        'session_id' => 'old-session',
        'branch_name' => 'yak/old-branch',
    ]);

    (new ClarificationReplyJob($task, 'Option A'))->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->repo)->toBe('unknown')
        ->and($task->session_id)->toBeNull()
        ->and($task->clarification_options)->toBe(['acme/api'])
        ->and(RepoClarificationResolver::awaitingRepoChoice($task))->toBeTrue();

    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});

test('a task that already has a pull request ignores the wrong repository verdict', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(wrongRepositoryResult('acme/api'));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/api']);

    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acme/atlas',
        'source' => 'linear',
        'branch_name' => 'yak/old-branch',
        'pr_url' => 'https://github.com/acme/atlas/pull/3',
    ]);

    (new RetryYakJob($task, 'ci output'))->handle($fake);

    expect($task->refresh()->repo)->toBe('acme/atlas')
        ->and(RepoClarificationResolver::awaitingRepoChoice($task))->toBeFalse();
});
