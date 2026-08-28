<?php

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function rawVideoWithStoryboard(YakTask $task, string $createdAt): Artifact
{
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm');
    Storage::disk('artifacts')->put("{$task->id}/storyboard.json", '{"version":1,"plan":{},"events":[]}');

    return Artifact::factory()->for($task, 'task')->create([
        'type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm", 'created_at' => $createdAt,
    ]);
}

beforeEach(function () {
    Storage::fake('artifacts');
    Queue::fake();
});

test('dispatches a render for raw videos that have a storyboard but no cut', function () {
    $missing = rawVideoWithStoryboard(YakTask::factory()->success()->create(), '2026-08-20 10:00:00');

    $done = YakTask::factory()->success()->create();
    rawVideoWithStoryboard($done, '2026-08-20 10:00:00');
    Artifact::factory()->for($done, 'task')->create(['type' => 'video_cut', 'filename' => 'reviewer-cut.mp4', 'disk_path' => "{$done->id}/reviewer-cut.mp4", 'created_at' => '2026-08-20 10:05:00']);

    $noStoryboard = YakTask::factory()->success()->create();
    Storage::disk('artifacts')->put("{$noStoryboard->id}/walkthrough.webm", 'webm');
    Artifact::factory()->for($noStoryboard, 'task')->create(['type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$noStoryboard->id}/walkthrough.webm"]);

    $this->artisan('yak:video:rerender')->assertSuccessful()->expectsOutputToContain('Dispatched 1 render(s)');

    Queue::assertPushed(RenderVideoJob::class, 1);
    Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->rawVideoArtifactId === $missing->id);
});

test('honours --failed-since, --task and --dry-run', function () {
    $old = rawVideoWithStoryboard(YakTask::factory()->success()->create(), '2026-08-01 10:00:00');
    $recent = rawVideoWithStoryboard(YakTask::factory()->success()->create(), '2026-08-20 10:00:00');

    $this->artisan('yak:video:rerender', ['--failed-since' => '2026-08-12'])->assertSuccessful();
    Queue::assertPushed(RenderVideoJob::class, 1);
    Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->rawVideoArtifactId === $recent->id);

    Queue::fake();
    $this->artisan('yak:video:rerender', ['--task' => $old->yak_task_id])->assertSuccessful();
    Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->rawVideoArtifactId === $old->id);

    Queue::fake();
    $this->artisan('yak:video:rerender', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Would dispatch 2 render(s)');
    Queue::assertNothingPushed();
});
