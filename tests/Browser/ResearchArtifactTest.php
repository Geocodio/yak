<?php

use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

test('artifact viewer page displays correct artifact filename', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('artifacts');
    Storage::disk('artifacts')->put(
        'research.html',
        '<html><body>Content</body></html>'
    );

    $task = YakTask::factory()->success()->create([
        'mode' => 'research',
    ]);

    Artifact::factory()->research()->create([
        'yak_task_id' => $task->id,
        'disk_path' => 'research.html',
    ]);

    $page = visit(route('artifacts.viewer', ['task' => $task->public_id, 'filename' => 'research.html']));

    $page->assertSee('research.html')
        ->assertPresent('[data-testid="artifact-iframe"]');
});
