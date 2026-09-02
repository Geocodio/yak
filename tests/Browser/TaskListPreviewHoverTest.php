<?php

use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;

test('hovering a task row with a preview gif shows it in the floating overlay', function () {
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

    $overlayImage = '[data-testid="task-preview-overlay-image"]';

    $page = visit(route('tasks'));

    $page->assertSee('Hover preview task')
        ->assertMissing('[data-testid="task-preview-overlay"]')
        ->hover('[data-testid="task-row-' . $task->id . '"]')
        ->assertVisible('[data-testid="task-preview-overlay"]')
        ->assertAttributeContains($overlayImage, 'src', '/artifacts/public/');
});
