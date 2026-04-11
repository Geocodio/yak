<?php

use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\TaskLogger;
use Illuminate\Support\Facades\Log;

test('TaskLogger::info creates a task log entry', function () {
    $task = YakTask::factory()->create();

    $log = TaskLogger::info($task, 'Task created', ['source' => 'slack']);

    expect($log)->toBeInstanceOf(TaskLog::class);
    expect($log->yak_task_id)->toBe($task->id);
    expect($log->level)->toBe('info');
    expect($log->message)->toBe('Task created');
    expect($log->metadata)->toBe(['source' => 'slack']);
});

test('TaskLogger::warning creates warning-level log', function () {
    $task = YakTask::factory()->create();

    $log = TaskLogger::warning($task, 'Task expired');

    expect($log->level)->toBe('warning');
    expect($log->message)->toBe('Task expired');
});

test('TaskLogger::error creates error-level log', function () {
    $task = YakTask::factory()->create();

    $log = TaskLogger::error($task, 'Task failed', ['error' => 'something broke']);

    expect($log->level)->toBe('error');
    expect($log->message)->toBe('Task failed');
    expect($log->metadata)->toBe(['error' => 'something broke']);
});

test('TaskLogger writes to yak log channel', function () {
    Log::shouldReceive('channel')
        ->with('yak')
        ->andReturn($channel = Mockery::mock());

    $channel->shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'Task created' && isset($context['task_id']);
        });

    $task = YakTask::factory()->create();
    TaskLogger::info($task, 'Task created');
});

test('task logs appear in task relationship', function () {
    $task = YakTask::factory()->create();

    TaskLogger::info($task, 'Task created');
    TaskLogger::info($task, 'Picked up by worker');
    TaskLogger::error($task, 'Task failed');

    $task->refresh();
    expect($task->logs)->toHaveCount(3);
    expect($task->logs->pluck('message')->all())->toBe([
        'Task created',
        'Picked up by worker',
        'Task failed',
    ]);
});

test('log entry without metadata stores null', function () {
    $task = YakTask::factory()->create();

    $log = TaskLogger::info($task, 'Task created');

    expect($log->metadata)->toBeNull();
});
