<?php

use App\Models\YakTask;
use App\Support\TaskContext;

afterEach(function () {
    TaskContext::clear();
});

test('set and read current task id', function () {
    $task = YakTask::factory()->create();

    TaskContext::set($task);

    expect(TaskContext::currentTaskId())->toBe($task->id);
});

test('clear resets current task id to null', function () {
    $task = YakTask::factory()->create();
    TaskContext::set($task);

    TaskContext::clear();

    expect(TaskContext::currentTaskId())->toBeNull();
});

test('set with null clears the context', function () {
    $task = YakTask::factory()->create();
    TaskContext::set($task);

    TaskContext::set(null);

    expect(TaskContext::currentTaskId())->toBeNull();
});

test('run restores previous context after callback', function () {
    $outer = YakTask::factory()->create();
    $inner = YakTask::factory()->create();

    TaskContext::set($outer);

    $observedInside = TaskContext::run($inner, fn () => TaskContext::currentTaskId());

    expect($observedInside)->toBe($inner->id);
    expect(TaskContext::currentTaskId())->toBe($outer->id);
});

test('run restores previous context when callback throws', function () {
    $outer = YakTask::factory()->create();
    $inner = YakTask::factory()->create();
    TaskContext::set($outer);

    expect(fn () => TaskContext::run($inner, function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(TaskContext::currentTaskId())->toBe($outer->id);
});
