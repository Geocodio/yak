<?php

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\VideoRenderer;
use Illuminate\Support\Facades\Storage;

/*
 * End-to-end Remotion render. Disabled by default because it spawns `npx
 * remotion render` and takes ~30s. Set YAK_E2E_RENDER=1 (and make sure
 * `video/node_modules` exists) to exercise the real pipeline — useful for
 * reproducing prod render hangs locally with the actual prod artifacts.
 *
 * To point the test at real prod artifacts instead of the committed
 * example fixtures, set:
 *   YAK_E2E_WEBM=/abs/path/walkthrough.webm
 *   YAK_E2E_STORYBOARD=/abs/path/storyboard.json
 */
it('renders an MP4 end-to-end through the real VideoRenderer', function () {
    if (env('YAK_E2E_RENDER') !== '1') {
        $this->markTestSkipped('Set YAK_E2E_RENDER=1 to run the real Remotion render.');
    }

    $webmSrc = env('YAK_E2E_WEBM', base_path('video/fixtures/example-walkthrough.webm'));
    $storyboardSrc = env('YAK_E2E_STORYBOARD', base_path('video/fixtures/example-storyboard.json'));

    expect(file_exists($webmSrc))->toBeTrue("missing webm fixture at {$webmSrc}");
    expect(file_exists($storyboardSrc))->toBeTrue("missing storyboard fixture at {$storyboardSrc}");

    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create();
    $rawVideo = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'walkthrough.webm',
        'disk_path' => "{$task->id}/walkthrough.webm",
    ]);

    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", file_get_contents($webmSrc));
    Storage::disk('artifacts')->put("{$task->id}/storyboard.json", file_get_contents($storyboardSrc));

    $renderer = new VideoRenderer(videoDir: base_path('video'));

    (new RenderVideoJob($rawVideo->id, 'reviewer'))->handle($renderer);

    $cut = Artifact::where('yak_task_id', $task->id)->where('type', 'video_cut')->first();

    expect($cut)->not->toBeNull();
    expect($cut->filename)->toBe('reviewer-cut.mp4');
    expect($cut->size_bytes)->toBeGreaterThan(100_000);

    $mp4Path = Storage::disk('artifacts')->path($cut->disk_path);
    expect(file_exists($mp4Path))->toBeTrue();
    expect(substr(file_get_contents($mp4Path, length: 12), 4, 4))->toBe('ftyp');
});
