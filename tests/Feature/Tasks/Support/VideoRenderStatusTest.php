<?php

use App\Models\Artifact;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Support\Tasks\VideoRenderStatus;

test('a task with no video artifacts is none', function () {
    $task = YakTask::factory()->success()->create();

    expect(VideoRenderStatus::for($task)->state)->toBe(VideoRenderStatus::None);
});

test('raw footage without a cut is rendering', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();

    expect(VideoRenderStatus::for($task)->state)->toBe(VideoRenderStatus::Rendering);
});

test('shot artifacts without a cut are rendering', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'role' => 'shot',
        'filename' => 'shots/intro.webm',
        'disk_path' => 'shots/intro.webm',
    ]);

    expect(VideoRenderStatus::for($task)->state)->toBe(VideoRenderStatus::Rendering);
});

test('a cut artifact is ready', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    expect(VideoRenderStatus::for($task)->state)->toBe(VideoRenderStatus::Ready);
});

test('the latest failed metric with no newer cut is failed and carries the reason', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_FAILED,
        'render_ms' => 1200,
        'error' => 'ffmpeg exited with code 1',
    ]);

    $status = VideoRenderStatus::for($task);

    expect($status->state)->toBe(VideoRenderStatus::Failed)
        ->and($status->error)->toBe('ffmpeg exited with code 1');
});

test('a cut rendered after the failed metric wins', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_FAILED,
        'render_ms' => 1200,
        'error' => 'transient',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);
    Artifact::factory()->for($task, 'task')->videoCut()->create(['created_at' => now()]);

    expect(VideoRenderStatus::for($task)->state)->toBe(VideoRenderStatus::Ready);
});

test('a rendered metric does not mark the task failed', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_RENDERED,
        'render_ms' => 900,
    ]);

    expect(VideoRenderStatus::for($task)->state)->toBe(VideoRenderStatus::Rendering);
});
