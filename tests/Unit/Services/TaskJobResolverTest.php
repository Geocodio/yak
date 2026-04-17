<?php

use App\Enums\TaskMode;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Models\YakTask;
use App\Services\TaskJobResolver;

it('resolves a fix task to RunYakJob', function () {
    $task = YakTask::factory()->make(['mode' => TaskMode::Fix]);

    expect(TaskJobResolver::jobClass($task))->toBe(RunYakJob::class);
});

it('resolves a research task to RunYakJob', function () {
    $task = YakTask::factory()->make(['mode' => TaskMode::Research]);

    expect(TaskJobResolver::jobClass($task))->toBe(RunYakJob::class);
});

it('resolves a review task to RunYakReviewJob', function () {
    $task = YakTask::factory()->make(['mode' => TaskMode::Review]);

    expect(TaskJobResolver::jobClass($task))->toBe(RunYakReviewJob::class);
});
