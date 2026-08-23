<?php

use App\Enums\TaskStatus;
use App\Models\YakTask;
use App\Services\ThreadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('single successful task yields user + yak entries', function () {
    $task = YakTask::factory()->create([
        'description' => 'Fix the thing',
        'result_summary' => 'Fixed it.',
        'status' => TaskStatus::Success,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now(),
    ]);

    $entries = app(ThreadBuilder::class)->build($task);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->kind)->toBe('user')
        ->and($entries[0]->text)->toBe('Fix the thing')
        ->and($entries[1]->kind)->toBe('yak')
        ->and($entries[1]->text)->toBe('Fixed it.')
        ->and($entries[1]->isLive)->toBeFalse();
});

test('follow-up chain yields entries for every run from any chain member', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'result_summary' => 'Done', 'started_at' => now()->subHour(), 'completed_at' => now()->subMinutes(50)]);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'description' => 'Also do X', 'status' => TaskStatus::Running, 'started_at' => now()]);

    $fromChild = app(ThreadBuilder::class)->build($child);

    expect($fromChild->pluck('kind')->all())->toBe(['user', 'yak', 'user', 'yak'])
        ->and($fromChild->last()->isLive)->toBeTrue();
});

test('clarification options render as a clarification entry', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Convert in place', 'Keep both'],
        'started_at' => now(),
    ]);

    $entries = app(ThreadBuilder::class)->build($task);
    $clarification = $entries->firstWhere('kind', 'clarification');

    expect($clarification)->not->toBeNull()
        ->and($clarification->options)->toBe(['Convert in place', 'Keep both']);
});

test('retries emit system lines and failed runs carry the error', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Failed,
        'error_log' => 'Sandbox died',
        'attempts' => 2,
        'started_at' => now(),
    ]);

    $entries = app(ThreadBuilder::class)->build($task);

    expect($entries->firstWhere('kind', 'system')->text)->toContain('attempt 2')
        ->and($entries->firstWhere('kind', 'yak')->error)->toBe('Sandbox died');
});

test('description_summary is exposed on the user entry', function () {
    $task = YakTask::factory()->create([
        'description' => str_repeat('long ', 400),
        'description_summary' => 'Short version',
    ]);

    $entries = app(ThreadBuilder::class)->build($task);

    expect($entries[0]->summary)->toBe('Short version');
});

test('user entries carry the task author name', function () {
    $task = YakTask::factory()->create([
        'description' => 'Fix the thing',
        'author_name' => 'Mathias',
        'status' => TaskStatus::Success,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now(),
    ]);

    $entries = app(ThreadBuilder::class)->build($task);

    expect($entries[0]->kind)->toBe('user')
        ->and($entries[0]->authorName)->toBe('Mathias');
});

test('user entries have a null author name when the task has none', function () {
    $task = YakTask::factory()->create(['description' => 'Fix', 'author_name' => null]);

    $entries = app(ThreadBuilder::class)->build($task);

    expect($entries[0]->authorName)->toBeNull();
});
