<?php

use App\Enums\TaskMode;
use App\Jobs\RenderVideoJob;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Artifact;
use App\Models\User;
use App\Models\VideoMetric;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('shows the rendered walkthrough in the lightbox when a cut exists', function () {
    $task = YakTask::factory()->success()->create();
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'role' => 'cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSeeHtml('data-testid="walkthrough-cut"');
});

test('the lightbox has no Director\'s Cut controls', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/owner/repo/pull/7',
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'role' => 'cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertDontSee("Director's Cut", escape: false)
        ->assertDontSeeHtml('data-testid="generate-director-cut"');
});

test('walkthrough player is hidden for a Review-mode task', function () {
    $task = YakTask::factory()->success()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/owner/repo/pull/7',
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'role' => 'cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertDontSeeHtml('data-testid="walkthrough-cut"');
});

test('the lightbox renders without a cut artifact', function () {
    $task = YakTask::factory()->success()->create();
    $recording = Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertOk()
        ->assertDontSeeHtml('data-testid="walkthrough-cut"');
});

test('the walkthrough card shows a rendering chip while a render is owed', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('data-testid="walkthrough-status-rendering"')
        ->assertSee('Rendering');
});

test('the walkthrough card shows a ready chip once the cut lands', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('data-testid="walkthrough-status-ready"');
});

test('the walkthrough card shows the failure reason', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_FAILED,
        'render_ms' => 10,
        'error' => 'ffmpeg exited with code 1',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('data-testid="walkthrough-status-failed"')
        ->assertSee('ffmpeg exited with code 1');
});

test('there is no walkthrough card when the task produced no video artifacts', function () {
    $task = YakTask::factory()->success()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertDontSeeHtml('data-testid="walkthrough-card"');
});

test('the preview image resolves through the public token route when one exists', function () {
    if (! Schema::hasColumn('artifacts', 'public_token')) {
        $this->markTestSkipped('artifacts.public_token is owned by track D1 and not migrated yet.');
    }

    if (! Route::has('artifacts.public')) {
        Route::get('/test-public/{token}', fn () => '')->name('artifacts.public');
    }

    $task = YakTask::factory()->success()->create();
    $preview = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_thumbnail',
        'role' => 'preview',
        'filename' => 'walkthrough-preview.gif',
        'disk_path' => 'walkthrough-preview.gif',
        'public_token' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
    ]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->instance()->previewUrl($preview))
        ->toBe(route('artifacts.public', ['token' => '01hzzzzzzzzzzzzzzzzzzzzzzz']));
});

test('the preview image falls back to a signed url without a public token', function () {
    $task = YakTask::factory()->success()->create();
    $thumb = Artifact::factory()->for($task, 'task')->videoThumbnail()->create(['public_token' => null]);

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->instance()->previewUrl($thumb))->toContain('signature=');
});

test('retry render dispatches a render job for the newest raw footage', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    $newest = Artifact::factory()->for($task, 'task')->video()->create();
    VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_FAILED,
        'render_ms' => 10,
        'error' => 'ffmpeg exited with code 1',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('data-testid="walkthrough-retry"')
        ->call('retryRender')
        ->assertOk();

    Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->rawVideoArtifactId === $newest->id);
});

test('retry render does nothing without raw footage', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'role' => 'shot',
        'filename' => 'shots/intro.webm',
        'disk_path' => 'shots/intro.webm',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('retryRender')
        ->assertOk();

    Queue::assertNothingPushed();
});

test('the retry button is absent when the render did not fail', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertDontSeeHtml('data-testid="walkthrough-retry"');
});

test('the lightbox shows the screenshot caption under the image', function () {
    if (! Schema::hasColumn('artifacts', 'caption')) {
        $this->markTestSkipped('artifacts.caption is owned by track D1 and not migrated yet.');
    }

    $task = YakTask::factory()->success()->create();
    $shot = Artifact::factory()->for($task, 'task')->screenshot()->create([
        'caption' => 'New ZIP-level section with the no-ZCTA warning',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $shot->id)
        ->assertSeeHtml('data-testid="artifact-caption"')
        ->assertSee('New ZIP-level section with the no-ZCTA warning');
});

test('the lightbox falls back to the filename when there is no caption', function () {
    $task = YakTask::factory()->success()->create();
    $shot = Artifact::factory()->for($task, 'task')->screenshot()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $shot->id)
        ->assertDontSeeHtml('data-testid="artifact-caption"')
        ->assertSee($shot->filename);
});

test('the retry button is absent when a v3 shoot left no raw footage', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'role' => 'shot',
        'filename' => 'shots/intro.webm',
        'disk_path' => 'shots/intro.webm',
    ]);
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'json',
        'role' => 'manifest',
        'filename' => 'manifest.json',
        'disk_path' => 'manifest.json',
    ]);
    VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_FAILED,
        'render_ms' => 10,
        'error' => 'ffmpeg exited with code 1',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('data-testid="walkthrough-status-failed"')
        ->assertSee('ffmpeg exited with code 1')
        ->assertDontSeeHtml('data-testid="walkthrough-retry"');
});

test('a Review-mode task opening its cut still shows the video', function () {
    $task = YakTask::factory()->success()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/owner/repo/pull/7',
    ]);
    $cut = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'role' => 'cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $cut->id)
        ->assertOk()
        ->assertDontSeeHtml('data-testid="walkthrough-player"')
        ->assertSeeHtml('<video controls preload="metadata"');
});

test('a negative ?t= deep link is clamped to the start of the walkthrough', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    $component = Livewire::withQueryParams(['t' => -30])
        ->test(TaskDetail::class, ['task' => $task]);

    expect($component->instance()->seekSeconds)->toBe(0);

    $component->assertSeeHtml('seekTo: 0');
});

test('the deep-link seek waits for the player ref instead of reading it at root-init time', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    Livewire::withQueryParams(['t' => 12])
        ->test(TaskDetail::class, ['task' => $task])
        ->assertSeeHtml('x-init="$nextTick(() => { const p = $refs.player;')
        ->assertSeeHtml('p.readyState >= 1');
});
