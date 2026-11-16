<?php

use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../Support/FinishTurnHelpers.php';

function wrongRepositoryEvent(?string $suggested): array
{
    $details = json_encode(['reason' => 'The billing code lives elsewhere.', 'suggested_repository' => $suggested]);

    return resultEvent("This issue belongs to a different repository.\n\n```wrong_repository\n{$details}\n```");
}

function wrongRepositoryResult(?string $suggested): AgentRunResult
{
    return new AgentRunResult(
        sessionId: 'sess_wrong_repo',
        resultSummary: 'This issue belongs to a different repository.',
        costUsd: 0.12,
        numTurns: 3,
        durationMs: 4000,
        isError: false,
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
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null, array $env = []): ProcessResult
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

    fakeAfterTurnCollaborators(shell: dirtySandbox());

    Repository::factory()->create(['slug' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/billing']);
    Repository::factory()->create(['slug' => 'acme/inactive', 'is_active' => false]);

    [$task, $run] = endedTurn(
        wrongRepositoryEvent('acme/billing'),
        ['repo' => 'acme/atlas', 'source' => 'linear', 'session_id' => 'old-session', 'branch_name' => 'yak/old-branch'],
        ['slug' => 'acme/atlas'],
    );

    runFinisher($run);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->repo)->toBe('unknown')
        ->and($task->session_id)->toBeNull()
        ->and($task->branch_name)->toBeNull()
        ->and($task->thread_id)->toBeNull()
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

    fakeAfterTurnCollaborators();

    Repository::factory()->create(['slug' => 'acme/zeta']);
    Repository::factory()->create(['slug' => 'acme/api']);

    [$task, $run] = endedTurn(wrongRepositoryEvent('acme/does-not-exist'), ['repo' => 'acme/atlas', 'source' => 'linear'], ['slug' => 'acme/atlas']);

    runFinisher($run);

    expect($task->refresh()->clarification_options)->toBe(['acme/api', 'acme/zeta']);
});

test('a retry that reports the wrong repository asks for a repo and starts over', function () {
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/billing']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acme/atlas',
        'source' => 'linear',
        'session_id' => 'old-session',
        'branch_name' => 'yak/old-branch',
    ]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, dirtySandbox(), wrongRepositoryEvent('acme/billing'), TaskRunKind::Retry);

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

test('a clarification turn that reports the wrong repository asks for a repo and starts over', function () {
    Queue::fake();

    fakeAfterTurnCollaborators(shell: dirtySandbox());

    Repository::factory()->create(['slug' => 'acme/api']);

    [$task, $run] = endedTurn(
        wrongRepositoryEvent(null),
        ['repo' => 'acme/atlas', 'source' => 'linear', 'session_id' => 'old-session', 'branch_name' => 'yak/old-branch'],
        ['slug' => 'acme/atlas'],
        TaskRunKind::Clarification,
    );

    runFinisher($run);

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

    Repository::factory()->create(['slug' => 'acme/atlas', 'path' => '/home/yak/repos/atlas']);
    Repository::factory()->create(['slug' => 'acme/api']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acme/atlas',
        'source' => 'linear',
        'branch_name' => 'yak/old-branch',
        'pr_url' => 'https://github.com/acme/atlas/pull/3',
    ]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, new FakeSandboxManager, wrongRepositoryEvent('acme/api'), TaskRunKind::Retry);

    expect($task->refresh()->repo)->toBe('acme/atlas')
        ->and(RepoClarificationResolver::awaitingRepoChoice($task))->toBeFalse();
});
