<?php

use App\Enums\NotificationType;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Process::fake(['*' => Process::result(exitCode: 1)]); // no container by default
});

test('reaps a Running task with no log activity past the threshold', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_log)->toContain('Worker crashed mid-run');
    expect($task->completed_at)->not->toBeNull();

    expect($task->stop_kind)->toBe(StopKind::YakStopped);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->task->id === $task->id && $job->type === NotificationType::Error
        && $job->personalize === false && $job->stop?->kind === StopKind::YakStopped);
});

test('spares a Running task that had log activity within the threshold', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'updated_at' => now()->subMinutes(30),
    ]);

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    Queue::assertNothingPushed();
});

test('spares a task still being updated (updated_at within threshold)', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

test('ignores non-Running, non-Retrying statuses', function () {
    Queue::fake();

    foreach ([TaskStatus::AwaitingCi, TaskStatus::AwaitingClarification, TaskStatus::Pending] as $status) {
        YakTask::factory()->create([
            'status' => $status,
            'updated_at' => now()->subHours(2),
        ]);
    }

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    // None of them flipped to Failed.
    expect(YakTask::where('status', TaskStatus::Failed)->count())->toBe(0);
});

test('reaps a Retrying task with no log activity past the threshold', function () {
    Queue::fake();

    $task = YakTask::factory()->retrying()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_log)->toContain('Worker crashed mid-run');
    expect($task->completed_at)->not->toBeNull();

    expect($task->stop_kind)->toBe(StopKind::YakStopped);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->task->id === $task->id && $job->type === NotificationType::Error
        && $job->personalize === false && $job->stop?->kind === StopKind::YakStopped);
});

test('spares a Retrying task that had log activity within the threshold', function () {
    Queue::fake();

    $task = YakTask::factory()->retrying()->create([
        'updated_at' => now()->subMinutes(30),
    ]);

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Retrying);
    Queue::assertNothingPushed();
});

test('does not notify system-source tasks', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'system',
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
});

test('a reaped setup task fails its repository setup and releases the tasks parked on it', function () {
    Queue::fake();
    $repository = Repository::factory()->create(['slug' => 'acme/parked-setup', 'setup_status' => 'running', 'sandbox_snapshot' => null]);
    $setupTask = YakTask::factory()->running()->create([
        'repo' => $repository->slug,
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
        'updated_at' => now()->subMinutes(30),
    ]);
    $repository->update(['setup_task_id' => $setupTask->id]);
    $parked = YakTask::factory()->create([
        'repo' => $repository->slug,
        'status' => TaskStatus::Failed,
        'source' => 'linear',
        'resume_after_setup_at' => now(),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])->assertSuccessful();

    expect($repository->fresh()->setup_status)->toBe('failed')
        ->and($parked->fresh()->resume_after_setup_at)->toBeNull()
        ->and($parked->fresh()->stop_summary)->toContain("couldn't get its workspace for acme/parked-setup ready");
});

test('stops the turn of a reaped thread task and leaves its sandbox alone', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id, 'updated_at' => now()->subMinutes(30)]);
    $turns = Mockery::mock(ThreadTurns::class);
    $turns->shouldReceive('stop')->once()->with(Mockery::on(fn (Thread $stopped): bool => $stopped->is($thread)));
    app()->instance(ThreadTurns::class, $turns);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Process::assertNothingRan();
});

test('spares a thread task whose turn waits on an unanswered live question', function () {
    Queue::fake();
    $task = YakTask::factory()->running()->create([
        'thread_id' => Thread::factory()->create()->id,
        'updated_at' => now()->subMinutes(30),
        'clarification_rounds' => [['questions' => [], 'answers' => null, 'live' => true]],
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});
