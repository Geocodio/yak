<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\StopKind;
use App\Enums\ThreadSource;
use App\Enums\TurnMessageState;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;

/**
 * A thread whose conversation has a message, a run with a few steps and an answer, with long unbroken
 * tokens in the title and the steps, the way real ones read.
 *
 * @param  array<string, mixed>  $threadAttributes
 * @param  array<string, mixed>  $taskAttributes
 * @return array{0: Thread, 1: YakTask, 2: TaskRun}
 */
function seedConversationThread(array $threadAttributes = [], array $taskAttributes = []): array
{
    $repository = Repository::query()->where('slug', 'o-r')->first() ?? Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'geocodio/geocodio', 'is_active' => true]);
    $thread = Thread::factory()->create([
        'repo' => $repository->slug,
        'title' => 'Fix flaky test Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest on commit b8dde56ba1ea5eaca13fa8299e701c14',
        'source' => ThreadSource::Linear,
        'source_reference' => 'ENG-1576',
        'source_url' => 'https://linear.app/geocodio/issue/ENG-1576',
        'starter_name' => 'Mathias Hansen',
        'branch' => 'yak/flaky-test-commit-b8dde56ba1ea5eaca13fa8299e701c14',
        ...$threadAttributes,
    ]);
    $task = YakTask::factory()->success()->create([
        'thread_id' => $thread->id,
        'repo' => $repository->slug,
        'source' => 'linear',
        'author_name' => 'Mathias Hansen',
        'description' => 'Delete the importer and the code only it used. ' . str_repeat('A long description without any natural break points ', 3),
        'branch_name' => $thread->branch,
        'pr_url' => 'https://github.com/geocodio/geocodio/pull/3105',
        'pr_number' => 3105,
        'pr_opened_at' => now(),
        ...$taskAttributes,
    ]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'commits' => 2, 'started_at' => now()->subHour()]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed, 'text' => $task->description]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'CI result received', 'metadata' => ['passed' => true], 'created_at' => now()->subMinutes(30)]);

    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 2, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'git rm app/Importer.php && vendor/bin/pest --filter=Tests\\\\Dash\\\\Integration\\\\Listeners\\\\NotifyPlanChangedToSlackTest']]]]]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 3, 'type' => 'entry', 'payload' => ['role' => 'tool_result', 'toolUseId' => 'toolu_1', 'isError' => false, 'text' => 'rm app/Importer.php']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 4, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'I removed the importer.', 'html' => '<p>I removed the importer. See https://github.com/geocodio/geocodio/commit/b8dde56ba1ea5eaca13fa8299e701c14</p>']]]]);

    foreach (range(5, 40) as $sequence) {
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => "Step {$sequence}", 'html' => "<p>Step {$sequence} of the longer conversation that makes the page scroll.</p>"]]]]);
    }
    $thread->forceFill(['event_sequence' => 40])->save();

    return [$thread, $task, $run];
}

/**
 * A thread held for a co-owner, the signed-in viewer being one of the people asked.
 */
function seedCoOwnerCardThread(User $viewer): Thread
{
    [$thread, $task] = seedConversationThread([], ['status' => 'awaiting_co_owner', 'pr_url' => null, 'pr_number' => null, 'pr_opened_at' => null, 'started_by_user_id' => User::factory()->create(['name' => 'Liam Hansen'])->id]);
    $request = CoOwnerRequest::factory()->create([
        'task_id' => $task->id,
        'repository_id' => Repository::query()->where('slug', 'o-r')->value('id'),
        'asked_user_ids' => [$viewer->id],
        'status' => CoOwnerRequestStatus::Pending,
        'additions' => 84,
        'deletions' => 2,
        'file_count' => 3,
    ]);
    cache()->put("co-owner:write-access:{$request->repository_id}:{$viewer->github_id}", 'write', now()->addMinutes(10));

    return $thread;
}

/**
 * A thread with the early warning card, the signed-in viewer being the requester.
 */
function seedEarlyWarningCardThread(User $viewer): Thread
{
    [$thread] = seedConversationThread([], [
        'status' => 'running', 'pr_url' => null, 'pr_number' => null, 'pr_opened_at' => null,
        'started_by_user_id' => $viewer->id,
        'early_warning' => ['areas' => [['name' => 'API response pipeline', 'risk' => 'high']], 'state' => 'shown'],
    ]);

    return $thread;
}

/**
 * A thread whose task stopped and shows the stop card.
 */
function seedStopCardThread(): Thread
{
    [$thread] = seedConversationThread([], [
        'status' => 'failed', 'pr_url' => null, 'pr_number' => null, 'pr_opened_at' => null,
        'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'CI failed twice on the same test.',
    ]);

    return $thread;
}

/**
 * Root threads of every source, with long titles, references and starters, for the thread log.
 */
function seedThreadLogRows(): void
{
    $repository = Repository::factory()->create(['slug' => 'a-repository-with-a-deliberately-long-slug-name']);
    $sources = [
        ['slack', 'C0123456789-1700000000.123456'],
        ['linear', 'ENG-1576'],
        ['sentry', 'GEOCODIO-PRODUCTION-API-4F2A9C1D7B'],
        ['github', 'geocodio/geocodio#3105'],
        ['email', 'b8dde56ba1ea5eaca13fa8299e701c14@mail.example.com'],
        ['dashboard', null],
        ['cli', null],
        ['teleport', null],
        ['stack', null],
        ['repository_research', null],
        ['preview_repair', null],
    ];

    foreach ($sources as [$source, $reference]) {
        $thread = Thread::factory()->create([
            'repo' => $repository->slug,
            'title' => "Fix flaky test Tests\\Dash\\Integration\\Listeners\\NotifyPlanChangedToSlackTest from {$source}",
            'source' => $source,
            'source_reference' => $reference,
            'starter_name' => 'A person with a rather long full name indeed',
            'last_activity_at' => now(),
        ]);
        YakTask::factory()->running()->create(['thread_id' => $thread->id, 'repo' => $repository->slug]);
    }
}
