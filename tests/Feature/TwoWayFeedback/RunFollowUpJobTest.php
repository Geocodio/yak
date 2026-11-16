<?php

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Queue::fake([ProcessCIResultJob::class, SendNotificationJob::class]);
    Repository::factory()->create(['slug' => 'fu-repo', 'path' => '/home/yak/repos/fu-repo']);
});

function pendingFollowUpTask(array $attributes = []): YakTask
{
    return YakTask::factory()->create([
        'status' => TaskStatus::Pending,
        'repo' => 'fu-repo',
        'session_id' => 'sess_parent',
        'branch_name' => 'yak/CSV-1',
        'pr_url' => 'https://github.com/acme/fu-repo/pull/9',
        'pr_number' => 9,
        'description' => 'Also handle the empty-state',
        ...$attributes,
    ]);
}

test('the finisher force-pushes the existing branch and parks at awaiting_ci', function () {
    $task = pendingFollowUpTask();
    $shell = new FakeSandboxManager;

    finishTaskTurn(FinishFollowUpTurnJob::class, $task, $shell, 'Addressed the feedback');

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($shell->commandsMatching('git push --force-with-lease'))->toHaveCount(1)
        ->and($shell->commandsMatching('git checkout -b'))->toBe([]);
});

test('the finisher stores the change summary and the rewritten description separately', function () {
    $task = pendingFollowUpTask();
    $output = "## What changed in this run\n\n- Added backoff\n\n## Replies\n\n- [c:42] Fixed in a1b2c3d.\n\n## PR description\n\n## Summary\n\nWhole PR, rewritten.";

    finishTaskTurn(FinishFollowUpTurnJob::class, $task, new FakeSandboxManager, $output);

    $task->refresh();
    expect($task->result_summary)->toBe('- Added backoff')
        ->and($task->pr_body_update)->toBe("## Summary\n\nWhole PR, rewritten.")
        ->and($task->review_replies)->toBe([42 => 'Fixed in a1b2c3d.']);
});

test('the finisher stores a null result_summary when the changes section is blank', function () {
    $task = pendingFollowUpTask();

    finishTaskTurn(FinishFollowUpTurnJob::class, $task, new FakeSandboxManager, "## What changed in this run\n\n   \n\n## PR description\n\nUnchanged.");

    expect($task->fresh()->result_summary)->toBeNull();
});

test('the finisher goes straight to the PR step when the repository has no CI', function () {
    Repository::query()->where('slug', 'fu-repo')->update(['ci_system' => 'none']);
    $task = pendingFollowUpTask();

    finishTaskTurn(FinishFollowUpTurnJob::class, $task, new FakeSandboxManager, 'Done');

    Queue::assertPushed(ProcessCIResultJob::class);
    expect($task->fresh()->status)->not->toBe(TaskStatus::AwaitingCi);
});

test('the finisher skips the push and resolves when there are no new commits', function () {
    $task = pendingFollowUpTask();
    $shell = (new FakeSandboxManager)->setCommitCount(0);

    finishTaskTurn(FinishFollowUpTurnJob::class, $task, $shell, 'Nothing to change');

    expect($shell->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(ProcessCIResultJob::class);
});

test('the finisher pushes and awaits CI when the commit count check fails', function () {
    $task = pendingFollowUpTask();
    $shell = (new FakeSandboxManager)->failCommand('rev-list --count');

    finishTaskTurn(FinishFollowUpTurnJob::class, $task, $shell, 'Done');

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($shell->commandsMatching('git push --force-with-lease'))->toHaveCount(1);
});

test('a follow-up run stamps started_at when the worker picks it up', function () {
    $context = pendingRunYak();
    $context['task']->update(['branch_name' => 'yak/CSV-1', 'started_at' => null]);

    (new RunFollowUpJob($context['task']->refresh()))->handle();

    expect($context['task']->fresh()->started_at)->not->toBeNull()
        ->and($context['task']->runs()->sole()->kind)->toBe(TaskRunKind::FollowUp);
});

test('a follow-up that has no branch fails and still stamps started_at', function () {
    $context = pendingRunYak();
    $context['task']->update(['branch_name' => null, 'started_at' => null]);

    (new RunFollowUpJob($context['task']->refresh()))->handle();

    expect($context['task']->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($context['task']->fresh()->started_at)->not->toBeNull();
});

test('a follow-up runs on the yak-claude queue', function () {
    expect((new RunFollowUpJob(YakTask::factory()->make()))->queue)->toBe('yak-claude');
});

test('a follow-up resumed with answers sends only the answers prompt into the existing session', function () {
    $context = pendingRunYak();
    $task = $context['task'];
    $task->update(['branch_name' => 'yak/CSV-1']);
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $task->recordClarificationAnswers(['scope' => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');

    (new RunFollowUpJob($task->refresh()))->handle();

    expect($context['sent'][1]['text'])->toContain('A: Option A');
});
