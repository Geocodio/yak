<?php

use App\Livewire\Tasks\TaskList;
use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a task with a preview artifact renders a poster and a data-preview-src', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_thumbnail',
        'role' => 'preview',
        'filename' => 'walkthrough-preview.gif',
        'disk_path' => 'walkthrough-preview.gif',
    ]);

    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="task-preview-' . $task->id . '"')
        ->assertSeeHtml('data-preview-src=');
});

test('a task with only a poster renders the image without a preview source', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();

    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="task-preview-' . $task->id . '"')
        ->assertDontSeeHtml('data-preview-src=');
});

test('a task with no video images renders no thumbnail', function () {
    $task = YakTask::factory()->success()->create();

    Livewire::test(TaskList::class)
        ->assertDontSeeHtml('data-testid="task-preview-' . $task->id . '"');
});

test('the thumbnail links to the task page with a zero seek deep link', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();

    Livewire::test(TaskList::class)
        ->assertSeeHtml(route('tasks.show', $task) . '?t=0');
});
