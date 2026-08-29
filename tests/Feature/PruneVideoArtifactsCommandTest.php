<?php

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('artifacts');
    config()->set('yak.video.raw_retention_days', 30);
});

function artifactWithFile(YakTask $task, string $role, string $relativePath): Artifact
{
    $diskPath = "{$task->id}/{$relativePath}";
    Storage::disk('artifacts')->put($diskPath, 'x');

    return Artifact::factory()->for($task, 'task')->create([
        'type' => 'file', 'role' => $role, 'filename' => basename($relativePath), 'disk_path' => $diskPath, 'size_bytes' => 1,
    ]);
}

function taskWithRawAndCut(string $cutCreatedAt): YakTask
{
    $task = YakTask::factory()->success()->create();
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm');
    Storage::disk('artifacts')->put("{$task->id}/reviewer-cut.mp4", 'mp4');
    Artifact::factory()->for($task, 'task')->create(['type' => 'video', 'role' => 'raw', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm"]);
    Artifact::factory()->for($task, 'task')->create(['type' => 'video_cut', 'role' => 'cut', 'filename' => 'reviewer-cut.mp4', 'disk_path' => "{$task->id}/reviewer-cut.mp4", 'created_at' => $cutCreatedAt]);
    Artifact::factory()->for($task, 'task')->create(['type' => 'file', 'role' => 'manifest', 'filename' => 'storyboard.json', 'disk_path' => "{$task->id}/storyboard.json"]);

    return $task;
}

test('deletes raw webm for tasks whose cut is older than the retention window', function () {
    $old = taskWithRawAndCut(now()->subDays(31)->toDateTimeString());
    $fresh = taskWithRawAndCut(now()->subDays(5)->toDateTimeString());

    $noCut = YakTask::factory()->success()->create();
    Storage::disk('artifacts')->put("{$noCut->id}/walkthrough.webm", 'webm');
    Artifact::factory()->for($noCut, 'task')->create(['type' => 'video', 'role' => 'raw', 'filename' => 'walkthrough.webm', 'disk_path' => "{$noCut->id}/walkthrough.webm", 'created_at' => now()->subDays(60)]);

    $this->artisan('yak:video:prune')->assertSuccessful()->expectsOutputToContain('Pruned 1 artifact(s)');

    expect(Artifact::where('yak_task_id', $old->id)->where('type', 'video')->exists())->toBeFalse()
        ->and(Storage::disk('artifacts')->exists("{$old->id}/walkthrough.webm"))->toBeFalse()
        ->and(Storage::disk('artifacts')->exists("{$old->id}/reviewer-cut.mp4"))->toBeTrue()
        ->and(Artifact::where('yak_task_id', $old->id)->count())->toBe(2)
        ->and(Artifact::where('yak_task_id', $fresh->id)->where('type', 'video')->exists())->toBeTrue()
        ->and(Artifact::where('yak_task_id', $noCut->id)->where('type', 'video')->exists())->toBeTrue();
});

test('--dry-run reports without deleting', function () {
    $old = taskWithRawAndCut(now()->subDays(45)->toDateTimeString());

    $this->artisan('yak:video:prune', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Would prune 1 artifact(s)');

    expect(Artifact::where('yak_task_id', $old->id)->where('type', 'video')->exists())->toBeTrue();
});

test('prune is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'yak:video:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

test('prunes shot and voiceover artifacts but keeps stills', function () {
    $task = YakTask::factory()->success()->create();
    $cut = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut', 'role' => 'cut', 'filename' => 'walkthrough.mp4', 'disk_path' => "{$task->id}/walkthrough.mp4", 'size_bytes' => 1,
    ]);
    $cut->update(['created_at' => now()->subDays(45)]);
    $shot = artifactWithFile($task, 'shot', 'shots/a.webm');
    $vo = artifactWithFile($task, 'voiceover', 'vo/a.mp3');
    $still = artifactWithFile($task, 'still', 'stills/a.png');

    $this->artisan('yak:video:prune')->assertSuccessful();

    expect(Artifact::find($shot->id))->toBeNull()
        ->and(Artifact::find($vo->id))->toBeNull()
        ->and(Artifact::find($still->id))->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists($shot->disk_path))->toBeFalse()
        ->and(Storage::disk('artifacts')->exists($vo->disk_path))->toBeFalse()
        ->and(Storage::disk('artifacts')->exists($still->disk_path))->toBeTrue();
});

test('keeps everything for a task whose render failed', function () {
    $task = YakTask::factory()->success()->create();
    $shot = artifactWithFile($task, 'shot', 'shots/a.webm');

    $this->artisan('yak:video:prune')->assertSuccessful();

    expect(Artifact::find($shot->id))->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists($shot->disk_path))->toBeTrue();
});
