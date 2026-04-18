<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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
