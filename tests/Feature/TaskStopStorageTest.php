<?php

use App\DataTransferObjects\TaskStop;
use App\Enums\StopKind;
use App\Enums\TaskStatus;
use App\Models\YakTask;

it('stores the stop kind, summary and technical detail', function () {
    $task = YakTask::factory()->failed()->create();

    $task->recordStop(TaskStop::yakSideProblem('boom'));

    $task->refresh();
    expect($task->stop_kind)->toBe(StopKind::YakStopped)
        ->and($task->stop_summary)->toBe('Yak ran into a problem on its side and stopped before finishing.')
        ->and($task->error_log)->toBe('boom');
});

it('clears the stop when the task leaves Failed or Expired', function () {
    $task = YakTask::factory()->failed()->create();
    $task->recordStop(TaskStop::yakSideProblem('boom'));

    $task->update(['status' => TaskStatus::Retrying]);

    $task->refresh();
    expect($task->stop_kind)->toBeNull()
        ->and($task->stop_summary)->toBeNull();
});

it('keeps the stop while the task stays Failed', function () {
    $task = YakTask::factory()->failed()->create();
    $task->recordStop(TaskStop::yakSideProblem('boom'));

    $task->update(['title' => 'Renamed']);

    expect($task->refresh()->stop_kind)->toBe(StopKind::YakStopped);
});
