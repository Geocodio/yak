<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskRunOutcome;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\TaskRun;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Queue::fake();
    $this->collaborators = fakeAfterTurnCollaborators();
    $this->shell = $this->collaborators['shell'];
});

it('pushes the branch and waits for CI after a turn with commits', function () {
    [$task, $run] = endedTurn(resultEvent('Fixed the bug successfully'));

    runFinisher($run);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->result_summary)->toBe('Fixed the bug successfully')
        ->and((float) $task->cost_usd)->toBe(0.25)
        ->and($this->shell->commandsMatching('git push origin yak/test-1'))->toHaveCount(1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Progress);
});

it('goes straight to the PR step when the repository has no CI', function () {
    [$task, $run] = endedTurn(repositoryAttributes: ['ci_system' => 'none']);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Running);
    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->passed === true);
    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Progress);
});

it('completes without a push when the agent answered without commits', function () {
    $this->collaborators['shell']->setCommitCount(0);
    [$task, $run] = endedTurn(resultEvent('The middleware is idempotent.'));

    runFinisher($run);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('The middleware is idempotent.')
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertNotPushed(ProcessCIResultJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Result);
});

it('fails a turn that left uncommitted changes and no commits', function () {
    $shell = new class extends FakeSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
        {
            parent::run($containerName, $command, $timeout, $asRoot, $input, $output);

            return str_contains($command, 'git status --porcelain') ? Process::result(' M a.php') : parent::run($containerName, $command);
        }
    };
    $shell->setCommitCount(0);
    fakeAfterTurnCollaborators(shell: $shell);
    [$task, $run] = endedTurn();

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('uncommitted changes');
});

it('fails the task once when the agent reports an error', function () {
    [$task, $run] = endedTurn(resultEvent('boom', ['subtype' => 'error_during_execution', 'is_error' => true]));

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Error);
});

it('fails a turn that ended without a result', function () {
    [$task, $run] = endedTurn(false);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('without a result');
});

it('leaves a terminal task alone', function () {
    [$task, $run] = endedTurn(taskAttributes: ['status' => TaskStatus::Cancelled]);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($this->shell->commands)->toBe([]);
});

it('waits for a sandbox slot instead of failing the task', function () {
    $collaborators = fakeAfterTurnCollaborators(SandboxWake::waitingForSlot());
    [$task, $run] = endedTurn();

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Running)
        ->and($collaborators['shell']->commands)->toBe([]);
    $collaborators['sandbox']->shouldHaveReceived('pauseIfIdle');
});

it('parks the task and posts the questions when the agent asks', function () {
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray(), sampleQuestion('data')->toArray()]]) . "\n```";
    [$task, $run] = endedTurn(resultEvent("Two problems.\n\n{$block}"));

    runFinisher($run);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->clarificationRoundCount())->toBe(1)
        ->and($task->clarificationRounds()[0]['summary'])->toBe('Two problems.')
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Clarification
        && str_contains($job->message, 'I have 2 questions') && $job->personalize === false);
});

it('ignores questions on the third round and finishes normally', function () {
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('again')->toArray()]]) . "\n```";
    [$task, $run] = endedTurn(resultEvent("Answer only\n\n{$block}"));
    foreach (range(1, 3) as $round) {
        $task->recordClarificationRound([sampleQuestion("q{$round}")], 'Summary');
        $task->recordClarificationAnswers(["q{$round}" => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');
        $task->markClarificationAnswersConsumed();
    }

    runFinisher($run);

    expect($task->fresh()->status)->not->toBe(TaskStatus::AwaitingClarification)
        ->and($task->fresh()->clarificationRoundCount())->toBe(3)
        ->and($task->fresh()->result_summary)->not->toContain('```clarification');
    Queue::assertNotPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Clarification);
});

it('consumes the answers a clarification turn was started with', function () {
    [$task, $run] = endedTurn(kind: TaskRunKind::Clarification, taskAttributes: ['status' => TaskStatus::Running]);
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $task->recordClarificationAnswers(['scope' => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');

    runFinisher($run);

    expect($task->fresh()->clarificationAnswersAwaitingResume())->toBeNull();
});

it('records the outcome on the turn row instead of opening a second row', function () {
    [, $run] = endedTurn(resultEvent('boom', ['is_error' => true, 'subtype' => 'error_during_execution']));

    runFinisher($run);

    expect(TaskRun::query()->count())->toBe(1)
        ->and($run->fresh()->outcome)->toBe(TaskRunOutcome::Error);
});
