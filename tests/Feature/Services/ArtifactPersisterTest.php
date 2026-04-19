<?php

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('artifacts');
    Queue::fake();
});

it('creates one Artifact row per file and moves files out of the .yak-artifacts subdir', function () {
    $task = YakTask::factory()->create();

    $artifactsDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/screenshot.png', 'fake-png-data');
    file_put_contents($artifactsDir . '/report.html', '<html>report</html>');

    $artifacts = ArtifactPersister::persist($task);

    expect($artifacts)->toHaveCount(2);
    expect(Artifact::where('yak_task_id', $task->id)->count())->toBe(2);

    $taskDir = Storage::disk('artifacts')->path((string) $task->id);
    expect(file_exists($taskDir . '/screenshot.png'))->toBeTrue();
    expect(file_exists($taskDir . '/report.html'))->toBeTrue();
    expect(is_dir($artifactsDir))->toBeFalse();
});

it('dispatches RenderVideoJob for webm artifacts so the Remotion pipeline picks them up', function () {
    $task = YakTask::factory()->create();

    $artifactsDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/walkthrough.webm', 'fake-webm-bytes');
    file_put_contents($artifactsDir . '/storyboard.json', '{"chapters":[]}');

    ArtifactPersister::persist($task);

    $video = Artifact::where('yak_task_id', $task->id)->where('type', 'video')->first();
    expect($video)->not->toBeNull();

    Queue::assertPushed(RenderVideoJob::class, fn ($job) => $job->rawVideoArtifactId === $video->id);
});

it('returns an empty array when no artifacts directory exists', function () {
    $task = YakTask::factory()->create();

    expect(ArtifactPersister::persist($task))->toBe([]);
    expect(Artifact::where('yak_task_id', $task->id)->count())->toBe(0);
});
