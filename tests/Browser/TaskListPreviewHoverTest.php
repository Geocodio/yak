<?php

use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;

test('hovering a task-list thumbnail swaps in the preview gif', function () {
    if (! file_exists(base_path('node_modules/playwright-core'))) {
        $this->markTestSkipped('Playwright browsers are unavailable in this environment.');
    }

    $this->actingAs(User::factory()->create());

    $task = YakTask::factory()->success()->create(['description' => 'Hover preview task']);
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_thumbnail',
        'role' => 'preview',
        'filename' => 'walkthrough-preview.gif',
        'disk_path' => 'walkthrough-preview.gif',
    ]);

    $selector = '[data-testid="task-preview-' . $task->id . '"] img';

    $page = visit(route('tasks'));

    $page->assertSee('Hover preview task')
        ->assertAttributeContains($selector, 'src', 'walkthrough-thumbnail.jpg')
        ->hover('[data-testid="task-preview-' . $task->id . '"]')
        ->assertAttributeContains($selector, 'src', 'walkthrough-preview.gif');
});
