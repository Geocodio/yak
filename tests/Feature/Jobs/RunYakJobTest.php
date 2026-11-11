<?php

use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

it('prepares the branch, writes one initial turn and pushes nothing', function () {
    Queue::fake();
    $context = pendingRunYak(['external_id' => 'ISSUE-42']);

    (new RunYakJob($context['task']))->handle();

    $run = TaskRun::query()->where('thread_id', $context['thread']->id)->sole();
    $task = $context['task']->refresh();
    expect($run->kind)->toBe(TaskRunKind::Initial)
        ->and($run->turn_state)->toBe(TurnState::Running)
        ->and(array_column($context['sent'], 'command'))->toBe(['start_runner', 'turn'])
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->branch_name)->toBe('yak/ISSUE-42')
        ->and($context['thread']->refresh()->branch)->toBe('yak/ISSUE-42')
        ->and($context['shell']->commandsMatching('git checkout -B yak/ISSUE-42 origin/main'))->toHaveCount(1)
        ->and($context['shell']->commandsMatching('git push'))->toBe([]);
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

it('adds a counter suffix when the remote already has the branch', function () {
    Queue::fake();
    $shell = new class(['yak/ISSUE-42', 'yak/ISSUE-42-2']) extends FakeSandboxManager
    {
        /** @param  array<int, string>  $existingRemoteBranches */
        public function __construct(private array $existingRemoteBranches) {}

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null, array $env = []): ProcessResult
        {
            if (preg_match("/git ls-remote --heads origin '([^']+)'/", $command, $m)) {
                return in_array($m[1], $this->existingRemoteBranches, true)
                    ? Process::result("abc123\trefs/heads/{$m[1]}\n")
                    : Process::result('');
            }

            return parent::run($containerName, $command, $timeout);
        }
    };
    $context = pendingRunYak(['external_id' => 'ISSUE-42'], $shell);

    (new RunYakJob($context['task']))->handle();

    expect($context['task']->refresh()->branch_name)->toBe('yak/ISSUE-42-3');
});

it('increments attempts when it claims the task', function () {
    Queue::fake();
    $context = pendingRunYak();

    (new RunYakJob($context['task']))->handle();

    expect($context['task']->refresh()->attempts)->toBe(1);
});

it('builds the prompt from the task description', function () {
    Queue::fake();
    $context = pendingRunYak(['description' => 'Fix the flaky importer']);

    (new RunYakJob($context['task']))->handle();

    expect($context['sent'][1]['text'])->toContain('Fix the flaky importer');
});

it('with fresh_session_pending the tree is reset and the full prompt is sent', function () {
    Queue::fake();
    $context = pendingRunYak(['description' => 'Fix the flaky importer', 'branch_name' => 'yak/old']);
    TaskRun::factory()->create(['yak_task_id' => $context['task']->id, 'thread_id' => $context['thread']->id, 'turn_state' => TurnState::Completed]);
    $context['thread']->forceFill(['fresh_session_pending' => true])->save();

    (new RunYakJob($context['task']))->handle();

    expect($context['shell']->commandsMatching('git reset --hard && git clean -fd'))->toHaveCount(1)
        ->and($context['shell']->commandsMatching('git checkout -B'))->toHaveCount(1)
        ->and($context['sent'][1]['text'])->toContain('Fix the flaky importer');
});

it('a clarification resume in the same thread sends only the answers on the existing branch', function () {
    Queue::fake();
    $context = pendingRunYak(['branch_name' => 'yak/eng-1603']);
    $task = $context['task'];
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $context['thread']->id, 'turn_state' => TurnState::Completed]);
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $task->recordClarificationAnswers(['scope' => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');

    (new RunYakJob($task->fresh()))->handle();

    $run = TaskRun::query()->where('thread_id', $context['thread']->id)->latest('id')->first();
    expect($run->kind)->toBe(TaskRunKind::Clarification)
        ->and($context['sent'][1]['text'])->toContain("Q: Which scope should I use?\nA: Option A")
        ->and($context['shell']->commands)->toBe([])
        ->and($task->fresh()->branch_name)->toBe('yak/eng-1603');
});

it('a clarification resume in a new thread sends the task and the answers and checks out the branch', function () {
    Queue::fake();
    $context = pendingRunYak(['branch_name' => 'yak/eng-1603', 'description' => 'Fix the flaky importer']);
    $task = $context['task'];
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $task->recordClarificationAnswers(['scope' => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');

    (new RunYakJob($task->fresh()))->handle();

    expect($context['sent'][1]['text'])->toContain('Fix the flaky importer')->toContain('A: Option A')
        ->and($context['shell']->commandsMatching('git ls-remote --exit-code --heads origin'))->toHaveCount(1);
});

it('fails the task when the repo is unknown', function () {
    Repository::factory()->create(['slug' => 'repo-a', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'repo-b', 'is_active' => true]);
    $task = YakTask::factory()->pending()->create(['repo' => 'unknown']);

    (new RunYakJob($task))->handle();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('Could not determine which repo')->toContain('repo-a')->toContain('repo-b');
});

it('fails the task when the repo slug does not exist', function () {
    Repository::factory()->create(['slug' => 'actual-repo', 'is_active' => true]);
    $task = YakTask::factory()->pending()->create(['repo' => 'non-existent-repo']);

    (new RunYakJob($task))->handle();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain("'non-existent-repo' not found");
});

it('fails the task when no Claude account is available', function () {
    Queue::fake();
    $context = pendingRunYak();
    $context['thread']->update(['claude_account_id' => null]);
    app()->forgetInstance(ClaudeAccountResolver::class);
    app()->offsetUnset(ClaudeAccountResolver::class);

    (new RunYakJob($context['task']))->handle();

    expect($context['task']->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($context['task']->error_log)->toBe('No Claude account is available. Ask an admin to add a Yak system account.');
});

it('runs on the yak-claude queue with the budget and repo middleware but no CLI auth hold', function () {
    $job = new RunYakJob(YakTask::factory()->pending()->make());
    $classes = array_map(fn ($middleware) => $middleware::class, $job->middleware());

    expect($job->queue)->toBe('yak-claude')
        ->and($classes)->toContain(EnsureDailyBudget::class)->toContain(EnsureRepoReady::class)
        ->not->toContain(HoldsForClaudeAuth::class);
});

it('emits a start progress notification on the first attempt', function () {
    Queue::fake();
    $context = pendingRunYak();

    (new RunYakJob($context['task']))->handle();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Progress
        && str_contains($job->message, 'exploring the codebase'));
});

it('skips the start progress notification when it is disabled or on a retry', function (bool $enabled, int $attempts) {
    config()->set('yak.emit_start_progress', $enabled);
    Queue::fake();
    $context = pendingRunYak(['attempts' => $attempts]);

    (new RunYakJob($context['task']))->handle();

    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => str_contains($job->message, 'exploring the codebase'));
})->with([[false, 0], [true, 1]]);
