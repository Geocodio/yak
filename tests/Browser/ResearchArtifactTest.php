<?php

use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

test('research findings link opens viewer with iframe and back navigation works', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('local');
    Storage::disk('local')->put(
        'artifacts/research.html',
        '<html><body><h1>Research Results</h1></body></html>'
    );

    $task = YakTask::factory()->success()->create([
        'mode' => 'research',
        'result_summary' => 'Found 3 key insights',
    ]);

    Artifact::factory()->research()->create([
        'yak_task_id' => $task->id,
        'disk_path' => 'artifacts/research.html',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('View research artifact')
        ->click('View research artifact')
        ->assertPathBeginsWith('/artifacts/')
        ->assertPresent('[data-testid="artifact-iframe"]')
        ->assertPresent('[data-testid="back-to-task"]')
        ->click('[data-testid="back-to-task"]')
        ->assertPathIs('/tasks/'.$task->id)
        ->assertSee('Found 3 key insights');
});

test('artifact viewer page displays correct artifact filename', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::fake('local');
    Storage::disk('local')->put(
        'artifacts/research.html',
        '<html><body>Content</body></html>'
    );

    $task = YakTask::factory()->success()->create([
        'mode' => 'research',
    ]);

    Artifact::factory()->research()->create([
        'yak_task_id' => $task->id,
        'disk_path' => 'artifacts/research.html',
    ]);

    $page = visit(route('artifacts.viewer', ['task' => $task->id, 'filename' => 'research.html']));

    $page->assertSee('research.html')
        ->assertPresent('[data-testid="artifact-iframe"]');
});
