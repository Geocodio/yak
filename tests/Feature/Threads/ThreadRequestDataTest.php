<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\ThreadSource;
use App\Enums\TurnState;
use App\Http\Resources\ThreadRequestData;
use App\Models\CoOwnerRequest;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadRequestStatus;
use App\Services\Threads\ThreadTasks;
use Illuminate\Support\Facades\DB;

function taskOn(Thread $thread, string $state = 'pending', array $attributes = []): YakTask
{
    return YakTask::factory()->{$state}()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, ...$attributes]);
}

/**
 * One thread per status key plus the edge threads.
 *
 * @return array<string, Thread>
 */
function threadsByStatus(): array
{
    $threads = [];

    $threads['working'] = tap(Thread::factory()->create(), fn (Thread $thread) => taskOn($thread, 'running'));
    $threads['needs_you'] = tap(Thread::factory()->create(), fn (Thread $thread) => taskOn($thread, 'awaitingClarification'));
    $threads['stopped'] = tap(Thread::factory()->create(), fn (Thread $thread) => taskOn($thread, 'failed'));
    $threads['done'] = tap(Thread::factory()->create(), fn (Thread $thread) => taskOn($thread, 'success'));
    $threads['closed'] = tap(Thread::factory()->closed()->create(), fn (Thread $thread) => taskOn($thread, 'running'));
    $threads['waiting_for_co_owner'] = tap(Thread::factory()->create(), function (Thread $thread): void {
        $task = taskOn($thread, 'awaitingCoOwner');
        CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->id, 'status' => CoOwnerRequestStatus::Pending]);
    });
    $threads['no_task_done'] = Thread::factory()->create();
    $threads['no_task_working'] = tap(Thread::factory()->create(), fn (Thread $thread) => TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]));
    $threads['no_repository'] = tap(Thread::factory()->create(['repo' => Thread::UNKNOWN_REPOSITORY]), fn (Thread $thread) => taskOn($thread, 'running'));
    $threads['guard_stopped'] = tap(Thread::factory()->create(), function (Thread $thread): void {
        taskOn($thread, 'success');
        TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::StoppedByGuard]);
    });
    $threads['active_turn_on_done_task'] = tap(Thread::factory()->create(), function (Thread $thread): void {
        taskOn($thread, 'success');
        TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::WaitingForAccount]);
    });
    $threads['moved'] = Thread::factory()->closed()->create(['close_reason' => 'moved']);
    $threads['legacy'] = Thread::factory()->closed()->create(['is_legacy' => true, 'close_reason' => 'legacy']);

    return $threads;
}

it('gives one status per key and the SQL scope agrees', function () {
    $threads = threadsByStatus();
    $expected = [
        'working' => 'working', 'needs_you' => 'needs_you', 'stopped' => 'stopped', 'done' => 'done', 'closed' => 'closed',
        'waiting_for_co_owner' => 'waiting_for_co_owner', 'no_task_done' => 'done', 'no_task_working' => 'working',
        'no_repository' => 'needs_you', 'guard_stopped' => 'stopped', 'active_turn_on_done_task' => 'working',
        'moved' => 'closed', 'legacy' => 'closed',
    ];

    foreach ($expected as $name => $key) {
        expect(ThreadRequestStatus::for($threads[$name])['key'])->toBe($key, $name);
    }

    expect(ThreadRequestStatus::for($threads['working']))->toBe(['key' => 'working', 'label' => 'Working', 'tone' => 'info']);

    foreach (array_unique(array_values($expected)) as $key) {
        $viaScope = ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), [$key])->pluck('id')->sort()->values()->all();
        $viaFor = collect($threads)->filter(fn (Thread $thread) => ThreadRequestStatus::for($thread)['key'] === $key)->pluck('id')->sort()->values()->all();

        expect($viaScope)->toBe($viaFor, $key);
    }

    expect(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), [])->count())->toBe(count($threads));
    expect(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), ['bogus'])->count())->toBe(count($threads));
    expect(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), ['working', 'done'])->count())
        ->toBe(collect($threads)->filter(fn (Thread $thread) => in_array(ThreadRequestStatus::for($thread)['key'], ['working', 'done'], true))->count());
});

it('names the request source and starter from the thread', function () {
    $thread = Thread::factory()->create(['source' => ThreadSource::Dashboard, 'starter_name' => 'Ada']);
    taskOn($thread, 'success', ['responsible_name' => 'Grace']);

    $data = ThreadRequestData::for($thread, User::factory()->create());

    expect($data['request']['source']['key'])->toBe('dashboard');
    expect($data['request']['starter']['name'])->toBe('Ada');
    expect($data['request']['responsibleName'])->toBe('Grace');
    expect($data['request']['status']['key'])->toBe('done');
});

it('shows no progress for a finished thread and the steps for a working or stopped one', function () {
    $done = Thread::factory()->create();
    taskOn($done, 'success');
    expect(ThreadRequestData::for($done, null)['progress'])->toBeNull();

    $stopped = Thread::factory()->create();
    taskOn($stopped, 'failed');
    $steps = ThreadRequestData::for($stopped, null)['progress']['steps'];
    expect($steps[0])->toMatchArray(['key' => 'received', 'label' => 'Received', 'state' => 'done']);
    expect(collect($steps)->pluck('state')->unique()->all())->toContain('upcoming');

    $running = Thread::factory()->create();
    taskOn($running, 'running');
    expect(collect(ThreadRequestData::for($running, null)['progress']['steps'])->firstWhere('state', 'current')['key'])->toBe('working');

    expect(ThreadRequestData::for(Thread::factory()->create(), null)['progress'])->toBeNull();
});

it('sums run details over the thread runs and reports a failed latest run', function () {
    $thread = Thread::factory()->create();
    $task = taskOn($thread, 'failed', ['attempts' => 2]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'cost_usd' => 1.25, 'num_turns' => 10, 'total_ms' => 60_000, 'started_at' => '2026-01-01 10:00:00', 'finished_at' => '2026-01-01 10:01:00']);
    TaskRun::factory()->failed()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'cost_usd' => 0.75, 'num_turns' => 5, 'total_ms' => 30_000, 'error_message' => str_repeat('x', 20_000), 'started_at' => '2026-01-01 11:00:00', 'finished_at' => '2026-01-01 11:00:30']);
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => 'sess-1']);

    $details = ThreadRequestData::for($thread, null)['runDetails'];

    expect($details)->toMatchArray([
        'runCount' => 2, 'stepCount' => 15, 'attempts' => '2 of 2', 'durationSeconds' => 90, 'costUsd' => 2.0,
        'apiSpendUsd' => 0.0, 'sessionId' => 'sess-1', 'errorLogTruncated' => true,
    ]);
    expect(strlen($details['errorLog']))->toBe(16384);
    expect($details['startedAt'])->toBe('2026-01-01T10:00:00+00:00');
    expect($details['completedAt'])->toBe('2026-01-01T11:00:30+00:00');
});

it('has no error log when the latest run succeeded', function () {
    $thread = Thread::factory()->create();
    $task = taskOn($thread, 'success');
    TaskRun::factory()->failed()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);

    $details = ThreadRequestData::for($thread, null)['runDetails'];

    expect($details['errorLog'])->toBeNull();
    expect($details['errorLogTruncated'])->toBeFalse();
});

it('answers for threads with no task, a moved thread and a legacy thread', function () {
    $moved = Thread::factory()->closed()->create(['close_reason' => 'moved', 'source' => ThreadSource::Slack]);
    TaskRun::factory()->create(['thread_id' => $moved->id, 'cost_usd' => 0.5]);

    expect(ThreadTasks::head($moved))->toBeNull();
    $data = ThreadRequestData::for($moved, null);
    expect($data['request']['source']['key'])->toBe('slack');
    expect($data['request']['status']['key'])->toBe('closed');
    expect($data['progress'])->toBeNull();
    expect($data['runDetails']['runCount'])->toBe(1);

    $root = YakTask::factory()->success()->create(['thread_id' => null, 'responsible_name' => 'Grace']);
    $legacy = Thread::factory()->closed()->create(['is_legacy' => true, 'close_reason' => 'legacy', 'legacy_root_task_id' => $root->id]);

    expect(ThreadTasks::request($legacy)?->id)->toBe($root->id);
    expect(ThreadRequestData::for($legacy, null)['request']['responsibleName'])->toBe('Grace');
});

it('runs a small fixed number of queries', function () {
    $thread = Thread::factory()->create();
    $root = taskOn($thread, 'success');
    $head = taskOn($thread, 'running', ['parent_task_id' => $root->id]);
    TaskRun::factory()->count(5)->create(['yak_task_id' => $head->id, 'thread_id' => $thread->id]);

    DB::enableQueryLog();
    ThreadRequestData::for($thread, null);
    $count = count(DB::getQueryLog());

    TaskRun::factory()->count(5)->create(['yak_task_id' => $head->id, 'thread_id' => $thread->id]);
    $fresh = $thread->fresh();
    DB::flushQueryLog();
    ThreadRequestData::for($fresh, null);

    expect(count(DB::getQueryLog()))->toBe($count);
    expect($count)->toBeLessThanOrEqual(14);
});
