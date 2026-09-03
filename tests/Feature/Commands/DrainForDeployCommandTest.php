<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\TaskLog;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::forget(PausesDuringDrain::CACHE_KEY);
    Process::fake(['*' => Process::result(exitCode: 1)]); // no sandbox containers by default
});

test('sets the drain cache flag', function () {
    Queue::fake();

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect(Cache::has(PausesDuringDrain::CACHE_KEY))->toBeTrue();
});

test('exits immediately when no Running tasks exist', function () {
    Queue::fake();

    YakTask::factory()->pending()->create();
    YakTask::factory()->success()->create();

    $this->artisan('yak:drain', ['--wait' => 10, '--poll' => 1])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('forces stragglers to Failed after the wait budget is exhausted', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
    ]);

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('Deploy interrupted')
        ->and($task->completed_at)->not->toBeNull();

    Queue::assertPushed(
        SendNotificationJob::class,
        fn ($job) => $job->task->id === $task->id && $job->type === NotificationType::Error,
    );
});

test('marks a straggler with interrupted_by_deploy_at', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create();

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect($task->fresh()->interrupted_by_deploy_at)->not->toBeNull();
});

test('promises automatic resume for a task claimed by one of the four resumable jobs', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'claimed_job_class' => RunYakJob::class,
    ]);

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect($task->fresh()->error_log)->toContain('resume automatically');
});

test('tells the operator to retry manually when the straggler is not resumable', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'claimed_job_class' => RunFollowUpJob::class,
    ]);

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect($task->fresh()->error_log)->toContain('manual retry');
});

test('a Retrying straggler always gets a manual-retry message, even with a resumable claimed_job_class', function () {
    Queue::fake();

    $task = YakTask::factory()->retrying()->create([
        // claimed_job_class reflects the original RunYakJob claim from
        // before the task reached AwaitingCi — RetryYakJob never touches
        // it — so status, not claimed_job_class, must be what excludes a
        // Retrying task from the resumable message.
        'claimed_job_class' => RunYakJob::class,
    ]);

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect($task->fresh()->error_log)->toContain('manual retry');
});

test('does not notify system-source stragglers', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create(['source' => 'system']);

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
});

test('ignores AwaitingCi and AwaitingClarification tasks', function () {
    Queue::fake();

    foreach ([TaskStatus::AwaitingCi, TaskStatus::AwaitingClarification, TaskStatus::Pending, TaskStatus::Success] as $status) {
        YakTask::factory()->create(['status' => $status]);
    }

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect(YakTask::where('status', TaskStatus::Failed)->count())->toBe(0);
});

test('covers a Retrying task, not just Running', function () {
    Queue::fake();

    $task = YakTask::factory()->retrying()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
    ]);

    $this->artisan('yak:drain', ['--wait' => 0, '--poll' => 1])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

test('waits while a task keeps logging, then completes once it finishes', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);

    $task = YakTask::factory()->running()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'created_at' => now(),
    ]);

    // On each fake sleep, refresh the task's liveness: keep it logging
    // for the first couple of polls, then let it finish.
    $polls = 0;
    Sleep::whenFakingSleep(function () use ($task, &$polls) {
        $polls++;

        if ($polls < 3) {
            TaskLog::factory()->create([
                'yak_task_id' => $task->id,
                'created_at' => now(),
            ]);
        } else {
            $task->update(['status' => TaskStatus::Success, 'completed_at' => now()]);
        }
    });

    $this->artisan('yak:drain', ['--wait' => 60, '--max-wait' => 2700, '--poll' => 60])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Success);
    // It kept waiting instead of force-failing at the first opportunity.
    Sleep::assertSleptTimes(3);
});

test('force-fails a silent task only once --wait has elapsed, not immediately', function () {
    Queue::fake();
    Sleep::fake();

    $task = YakTask::factory()->running()->create();

    $this->artisan('yak:drain', ['--wait' => 10, '--max-wait' => 2700, '--poll' => 5])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('Deploy interrupted the task after 10s with no activity');

    // Two 5s polls elapsed (0s, 5s) before the task was declared past its
    // wait budget at 10s — it wasn't failed on the very first check.
    Sleep::assertSleptTimes(2);
});

test('force-fails everything at --max-wait regardless of liveness', function () {
    Queue::fake();
    Sleep::fake();

    $task = YakTask::factory()->running()->create();

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'created_at' => now(),
    ]);

    $this->artisan('yak:drain', ['--wait' => 999999, '--max-wait' => 0, '--poll' => 5])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('drain ceiling');
});

test('derives the cache flag TTL from --max-wait so it outlasts the drain', function () {
    Queue::fake();

    $this->artisan('yak:drain', ['--wait' => 0, '--max-wait' => 2700, '--poll' => 1])
        ->assertSuccessful();

    // TTL is max-wait + 600 = 3300s. Just before that, the flag holds;
    // just after, it's gone.
    $this->travel(3299)->seconds();
    expect(Cache::has(PausesDuringDrain::CACHE_KEY))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has(PausesDuringDrain::CACHE_KEY))->toBeFalse();
});
