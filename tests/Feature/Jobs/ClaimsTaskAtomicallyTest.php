<?php

use App\Enums\TaskStatus;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;

test('claims a pending task and calls next', function () {
    Repository::factory()->create(['slug' => 'claim-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'claim-repo', 'attempts' => 0]);

    $job = new RunYakJob($task);

    $called = false;
    (new ClaimsTaskAtomically)->handle($job, function () use (&$called) {
        $called = true;
    });

    $task->refresh();

    expect($called)->toBeTrue()
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->attempts)->toBe(1)
        ->and($task->started_at)->not->toBeNull();
});

test('deletes the job and skips next when the task is not pending', function () {
    Repository::factory()->create(['slug' => 'claim-repo-2']);
    $task = YakTask::factory()->running()->create(['repo' => 'claim-repo-2', 'attempts' => 1]);

    $job = new RunYakJob($task);

    $called = false;
    (new ClaimsTaskAtomically)->handle($job, function () use (&$called) {
        $called = true;
    });

    $task->refresh();

    // No side effects: attempts and status are untouched, and the
    // downstream pipeline (which could call $job->fail()) never ran.
    expect($called)->toBeFalse()
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->attempts)->toBe(1)
        ->and($job->taskClaimLost)->toBeTrue();
});

test('passes objects without a task property straight through', function () {
    $job = new stdClass;

    $called = false;
    (new ClaimsTaskAtomically)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue();
});

test('passes through jobs that do not support claimTask', function () {
    Repository::factory()->create(['slug' => 'claim-repo-3']);
    $task = YakTask::factory()->pending()->create(['repo' => 'claim-repo-3']);

    $job = new class($task)
    {
        public function __construct(public YakTask $task) {}
    };

    $called = false;
    (new ClaimsTaskAtomically)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue();
});
