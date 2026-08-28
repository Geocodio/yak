<?php

use App\Enums\TaskMode;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
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

use App\Models\VideoMetric;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

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
    $thumb = Artifact::factory()->for($task, 'task')->videoThumbnail()->create();

    $component = Livewire::test(TaskDetail::class, ['task' => $task]);

    expect($component->instance()->previewUrl($thumb))->toContain('signature=');
});
