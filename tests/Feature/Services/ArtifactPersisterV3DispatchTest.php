<?php

use App\Jobs\RenderVideoJob;
use App\Jobs\RenderWalkthroughJob;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('artifacts');
    Bus::fake();
});

it('dispatches exactly one task-keyed render for a v3 manifest', function (): void {
    $task = YakTask::factory()->create();
    $dir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists("{$dir}/shots");
    File::put("{$dir}/script.json", json_encode(['version' => 3, 'shots' => []]));
    File::put("{$dir}/manifest.json", json_encode(['version' => 3, 'shots' => [
        ['id' => 'a', 'clip' => 'shots/a.webm', 'start' => 0, 'end' => 4, 'rect' => null, 'url' => 'https://x/'],
        ['id' => 'b', 'clip' => 'shots/b.webm', 'start' => 0, 'end' => 4, 'rect' => null, 'url' => 'https://x/'],
    ]]));
    File::put("{$dir}/shots/a.webm", 'a');
    File::put("{$dir}/shots/b.webm", 'b');

    ArtifactPersister::persist($task);

    Bus::assertDispatchedTimes(RenderWalkthroughJob::class, 1);
    Bus::assertDispatched(RenderWalkthroughJob::class, fn (RenderWalkthroughJob $job): bool => $job->taskId === $task->id);
    Bus::assertNotDispatched(RenderVideoJob::class);
});

it('keeps the legacy path for a bare walkthrough.webm', function (): void {
    $task = YakTask::factory()->create();
    $dir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/walkthrough.webm", 'webm');

    ArtifactPersister::persist($task);

    Bus::assertNotDispatched(RenderWalkthroughJob::class);
    Bus::assertDispatchedTimes(RenderVideoJob::class, 1);
});

it('ignores a manifest that is not version 3', function (): void {
    $task = YakTask::factory()->create();
    $dir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/manifest.json", json_encode(['version' => 2, 'shots' => []]));
    File::put("{$dir}/walkthrough.webm", 'webm');

    ArtifactPersister::persist($task);

    Bus::assertNotDispatched(RenderWalkthroughJob::class);
    Bus::assertDispatchedTimes(RenderVideoJob::class, 1);
});
