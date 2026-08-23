<?php

use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\ChainMediaResolver;

test('latest returns the newest run that has media, with its run', function () {
    $root = YakTask::factory()->create();
    $child = YakTask::factory()->create(['parent_task_id' => $root->id]);
    Artifact::factory()->create(['yak_task_id' => $root->id, 'type' => 'screenshot', 'filename' => 'old.png']);
    Artifact::factory()->create(['yak_task_id' => $child->id, 'type' => 'screenshot', 'filename' => 'new.png']);

    $latest = app(ChainMediaResolver::class)->latest($root->conversation());

    expect($latest['run']->id)->toBe($child->id)
        ->and($latest['artifacts']->pluck('filename')->all())->toBe(['new.png']);
});

test('latest falls back to an earlier run when the newest has none', function () {
    $root = YakTask::factory()->create();
    $child = YakTask::factory()->create(['parent_task_id' => $root->id]);
    Artifact::factory()->create(['yak_task_id' => $root->id, 'type' => 'screenshot', 'filename' => 'only.png']);

    $latest = app(ChainMediaResolver::class)->latest($root->conversation());

    expect($latest['run']->id)->toBe($root->id);
});

test('forRun returns only screenshot and video artifacts for that run', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'filename' => 'a.png']);
    Artifact::factory()->create(['yak_task_id' => $task->id, 'type' => 'video', 'filename' => 'b.mp4']);
    Artifact::factory()->create(['yak_task_id' => $task->id, 'type' => 'research', 'filename' => 'c.html']);
    Artifact::factory()->create(['yak_task_id' => $task->id, 'type' => 'video_cut', 'filename' => 'd.mp4']);

    $artifacts = app(ChainMediaResolver::class)->forRun($task);

    expect($artifacts->pluck('filename')->sort()->values()->all())->toBe(['a.png', 'b.mp4']);
});

test('latest returns an empty result when no run in the chain has media', function () {
    $root = YakTask::factory()->create();

    $latest = app(ChainMediaResolver::class)->latest($root->conversation());

    expect($latest['run'])->toBeNull()
        ->and($latest['artifacts'])->toBeEmpty();
});
