<?php

use App\Enums\TaskStatus;
use App\Jobs\GenerateDirectorCutJob;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('shows reviewer cut video when artifact exists', function () {
    $task = YakTask::factory()->success()->create();
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSee('Reviewer Cut');
});

test('shows Generate Director\'s Cut button when PR is open and no cut exists', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/owner/repo/pull/7',
        'director_cut_status' => null,
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSee("Generate Director's Cut", escape: false);
});

test('dispatches GenerateDirectorCutJob when button clicked', function () {
    Queue::fake([GenerateDirectorCutJob::class]);
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/owner/repo/pull/7',
        'director_cut_status' => null,
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->call('generateDirectorCut');

    Queue::assertPushed(GenerateDirectorCutJob::class);
    expect($task->fresh()->director_cut_status)->toBe('queued');
});

test('button is hidden when task is not completed', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
        'pr_url' => 'https://github.com/owner/repo/pull/7',
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertDontSee("Generate Director's Cut", escape: false);
});

test('button is hidden when there is no PR', function () {
    $task = YakTask::factory()->success()->create(['pr_url' => null]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertDontSee("Generate Director's Cut", escape: false);
});

test('shows rendering indicator when status is rendering', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/owner/repo/pull/7',
        'director_cut_status' => 'rendering',
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSee('Rendering Director');
});

test('shows director cut video when status ready and artifact exists', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/owner/repo/pull/7',
        'director_cut_status' => 'ready',
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'filename' => 'director-cut.mp4',
        'disk_path' => 'director-cut.mp4',
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSee("Director's Cut", escape: false);
});

test('shows retry button when director cut failed', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/owner/repo/pull/7',
        'director_cut_status' => 'failed',
    ]);
    $recording = Artifact::factory()->for($task, 'task')->video()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSee('Retry');
});
