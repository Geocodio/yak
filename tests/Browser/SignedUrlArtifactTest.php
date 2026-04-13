<?php

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

test('screenshot accessible via signed url without auth', function () {
    Storage::fake('artifacts');
    Storage::disk('artifacts')->put(
        'screenshot.png',
        file_get_contents(__DIR__ . '/../Fixtures/1x1.png')
    );

    $task = YakTask::factory()->success()->create();

    $artifact = Artifact::factory()->screenshot()->create([
        'yak_task_id' => $task->id,
        'filename' => 'screenshot.png',
        'disk_path' => 'screenshot.png',
    ]);

    $signedUrl = $artifact->signedUrl();

    $this->get($signedUrl)->assertSuccessful();
});

test('expired signed url shows error', function () {
    Storage::fake('artifacts');
    Storage::disk('artifacts')->put(
        'screenshot.png',
        file_get_contents(__DIR__ . '/../Fixtures/1x1.png')
    );

    $task = YakTask::factory()->success()->create();

    Artifact::factory()->screenshot()->create([
        'yak_task_id' => $task->id,
        'filename' => 'screenshot.png',
        'disk_path' => 'screenshot.png',
    ]);

    $expiredUrl = URL::temporarySignedRoute(
        'artifacts.show',
        now()->subDay(),
        ['task' => $task->id, 'filename' => 'screenshot.png']
    );

    $page = visit($expiredUrl);

    $page->assertSee('403');
});

test('tampered signed url is rejected', function () {
    Storage::fake('artifacts');
    Storage::disk('artifacts')->put(
        'screenshot.png',
        file_get_contents(__DIR__ . '/../Fixtures/1x1.png')
    );

    $task = YakTask::factory()->success()->create();

    Artifact::factory()->screenshot()->create([
        'yak_task_id' => $task->id,
        'filename' => 'screenshot.png',
        'disk_path' => 'screenshot.png',
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'artifacts.show',
        now()->addDays(7),
        ['task' => $task->id, 'filename' => 'screenshot.png']
    );
    $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=tampered123', $signedUrl);

    $page = visit($tamperedUrl);

    $page->assertSee('403');
});
