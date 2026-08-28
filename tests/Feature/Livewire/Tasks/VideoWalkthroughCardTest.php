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
