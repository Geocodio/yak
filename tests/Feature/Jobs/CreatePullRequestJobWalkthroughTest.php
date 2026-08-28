<?php

use App\Jobs\CreatePullRequestJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\WalkthroughPrSection;

/**
 * `buildPrBody()` is private and the surrounding `handle()` needs a full
 * GitHub fake, so the body assertions reach the builder directly.
 *
 * @param  array<int, array{filename: string, url: string, type: string}>  $signedUrls
 */
function invokeBuildPrBody(YakTask $task, array $signedUrls): string
{
    $job = new CreatePullRequestJob($task);

    $method = new ReflectionMethod($job, 'buildPrBody');

    return (string) $method->invoke($job, $signedUrls);
}

it('writes the rendering placeholder when there is no cut', function (): void {
    $task = YakTask::factory()->create();

    $body = invokeBuildPrBody($task, []);

    expect($body)->toContain(WalkthroughPrSection::MARKER_START)
        ->toContain('_Rendering, this section will update automatically._');
});

it('embeds the gif by public url and captions the screenshots', function (): void {
    $task = YakTask::factory()->create();
    $cut = Artifact::create(['yak_task_id' => $task->id, 'type' => 'video_cut', 'role' => 'cut', 'filename' => 'walkthrough.mp4', 'disk_path' => "{$task->id}/walkthrough.mp4", 'size_bytes' => 1]);
    $gif = Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'preview', 'filename' => 'walkthrough-preview.gif', 'disk_path' => "{$task->id}/walkthrough-preview.gif", 'size_bytes' => 1]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'zip.png', 'disk_path' => "{$task->id}/screenshots/zip.png", 'size_bytes' => 1, 'caption' => 'New ZIP-level section']);

    $body = invokeBuildPrBody($task, []);

    expect($body)
        ->toContain("![walkthrough preview]({$gif->publicUrl()})")
        ->toContain('![New ZIP-level section](')
        ->toContain('_New ZIP-level section_')
        ->toContain('### Screenshots')
        ->toContain($cut->filename);
});
