<?php

use App\Enums\TaskMode;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunFollowUpJob;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function retryingRunYak(array $attributes = []): array
{
    $context = pendingRunYak($attributes);
    YakTask::query()->whereKey($context['task']->id)->update(['status' => TaskStatus::Retrying->value]);
    $context['task'] = $context['task']->refresh();

    return $context;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function followUpOn(array $context, array $attributes = []): YakTask
{
    $parent = YakTask::factory()->success()->create([
        'repo' => 'test-repo', 'source' => 'slack', 'thread_id' => $context['thread']->id,
        'branch_name' => 'yak/ISSUE-1', 'session_id' => 'sess_parent', 'mode' => $attributes['mode'] ?? TaskMode::Fix,
    ]);

    $context['task']->update([
        'parent_task_id' => $parent->id, 'thread_id' => $context['thread']->id, 'branch_name' => 'yak/ISSUE-1',
        'description' => 'Please also rename the helper', ...$attributes,
    ]);

    return $context['task']->refresh();
}

it('writes a follow-up turn on the parent thread and checks out the branch', function () {
    Queue::fake();
    $context = pendingRunYak();
    $task = followUpOn($context);

    (new RunFollowUpJob($task))->handle();

    $run = TaskRun::query()->where('yak_task_id', $task->id)->sole();
    expect($run->kind)->toBe(TaskRunKind::FollowUp)
        ->and($run->thread_id)->toBe($context['thread']->id)
        ->and($task->refresh()->status)->toBe(TaskStatus::Running)
        ->and($context['sent'][1]['text'])->toContain('rename the helper')
        ->and($context['shell']->commandsMatching('git checkout yak/ISSUE-1'))->not->toBe([])
        ->and($context['shell']->commandsMatching('git push'))->toBe([]);
});

it('fails a follow-up that has no branch', function () {
    Queue::fake();
    $context = pendingRunYak();
    $task = followUpOn($context);
    $task->update(['branch_name' => null]);

    (new RunFollowUpJob($task->refresh()))->handle();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and(TaskRun::query()->where('yak_task_id', $task->id)->exists())->toBeFalse();
});

it('a follow-up joins the thread of the task it continues', function () {
    Queue::fake();
    $context = pendingRunYak();
    $parent = YakTask::factory()->success()->create([
        'repo' => 'test-repo', 'thread_id' => $context['thread']->id, 'branch_name' => 'yak/ISSUE-1', 'pr_url' => 'https://github.com/o/r/pull/1', 'pr_number' => 1,
    ]);

    $child = app(FollowUpTaskFactory::class)->create($parent, 'More please', 'slack');

    expect($child->thread_id)->toBe($context['thread']->id);
});

it('a research follow-up never touches the working tree', function () {
    Queue::fake();
    $context = pendingRunYak();
    $task = followUpOn($context, ['mode' => TaskMode::Research, 'branch_name' => null]);

    (new ResearchFollowUpJob($task))->handle();

    $run = TaskRun::query()->where('yak_task_id', $task->id)->sole();
    expect($run->kind)->toBe(TaskRunKind::FollowUp)
        ->and($context['shell']->commandsMatching('git checkout'))->toBe([])
        ->and($context['shell']->commandsMatching('git commit'))->toBe([])
        ->and($context['shell']->commandsMatching('git reset'))->toBe([]);
});

it('research on a thread with no runs starts from the latest default branch', function () {
    Queue::fake();
    $context = pendingRunYak(['mode' => TaskMode::Research]);

    (new ResearchYakJob($context['task']))->handle();

    $run = TaskRun::query()->where('thread_id', $context['thread']->id)->sole();
    expect($run->kind)->toBe(TaskRunKind::Research)
        ->and($context['shell']->commandsMatching('git reset --hard origin/main'))->not->toBe([])
        ->and($context['shell']->commandsMatching('git push'))->toBe([]);
});

it('research on a thread that already ran leaves the tree alone', function () {
    Queue::fake();
    $context = pendingRunYak(['mode' => TaskMode::Research]);
    TaskRun::factory()->create(['yak_task_id' => $context['task']->id, 'thread_id' => $context['thread']->id, 'turn_state' => TurnState::Completed]);

    (new ResearchYakJob($context['task']))->handle();

    expect($context['shell']->commandsMatching('git reset'))->toBe([])
        ->and($context['shell']->commandsMatching('git checkout'))->toBe([]);
});

it('a CI retry fetches, resets to the pushed head and cleans, in that order', function () {
    Queue::fake();
    $context = retryingRunYak(['branch_name' => 'yak/ISSUE-1', 'pr_opened_at' => now()]);

    (new RetryYakJob($context['task'], 'CI failed: test X'))->handle();

    $commands = $context['shell']->commands;
    $fetch = collect($commands)->search(fn ($command) => str_contains($command, "git fetch origin 'yak/ISSUE-1'"));
    $reset = collect($commands)->search(fn ($command) => str_contains($command, "git reset --hard 'origin/yak/ISSUE-1'"));
    $clean = collect($commands)->search(fn ($command) => str_contains($command, 'git clean -fd'));
    $run = TaskRun::query()->where('thread_id', $context['thread']->id)->sole();

    expect($fetch)->not->toBeFalse()
        ->and($reset)->toBeGreaterThan($fetch)
        ->and($clean)->toBeGreaterThan($reset)
        ->and($run->kind)->toBe(TaskRunKind::Retry)
        ->and($run->thread_id)->toBe($context['thread']->id)
        ->and(array_column($context['sent'], 'command'))->toBe(['start_runner', 'turn'])
        ->and($context['sent'][1]['text'])->toContain('CI failed: test X');
});

it('a retry of a never-pushed branch only cleans and resumes the same session', function () {
    Queue::fake();
    $context = retryingRunYak(['branch_name' => 'yak/ISSUE-1', 'pr_opened_at' => null]);
    $context['shell']->failCommand('git ls-remote', '', 2);
    $sessionsBefore = $context['thread']->sessions()->count();

    (new RetryYakJob($context['task'], 'CI failed'))->handle();

    expect($context['shell']->commandsMatching('git fetch'))->toBe([])
        ->and($context['shell']->commandsMatching('git reset'))->toBe([])
        ->and($context['shell']->commandsMatching('git clean -fd'))->toHaveCount(1)
        ->and($context['thread']->sessions()->count())->toBe($sessionsBefore)
        ->and(TaskRun::query()->where('thread_id', $context['thread']->id)->sole()->kind)->toBe(TaskRunKind::Retry);
});

it('a retry on a pull request Yak did not open fails the task', function () {
    Queue::fake();
    $context = retryingRunYak(['targets_external_pr' => true]);

    (new RetryYakJob($context['task'], 'CI failed'))->handle();

    expect($context['task']->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($context['sent'])->toBe([]);
});
