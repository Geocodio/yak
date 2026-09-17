<?php

use App\Jobs\CreatePullRequestJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\PullRequestBodySections;
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

it('writes the rendering placeholder when a capture is waiting on its render', function (): void {
    $task = YakTask::factory()->create();
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'script', 'role' => 'script', 'filename' => 'script.json', 'disk_path' => "{$task->id}/script.json", 'size_bytes' => 1]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'manifest', 'role' => 'manifest', 'filename' => 'manifest.json', 'disk_path' => "{$task->id}/manifest.json", 'size_bytes' => 1]);

    $body = invokeBuildPrBody($task, []);

    expect($body)->toContain(WalkthroughPrSection::MARKER_START)
        ->toContain('_Rendering, this section will update automatically._');
});

it('omits the walkthrough section entirely when nothing was captured', function (): void {
    $task = YakTask::factory()->create();

    $body = invokeBuildPrBody($task, []);

    expect($body)->not->toContain(WalkthroughPrSection::MARKER_START)
        ->not->toContain('### Video walkthrough');
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

it('wraps the description and screenshots in owned markers on a new PR, screenshots ahead of the description', function (): void {
    $task = YakTask::factory()->create(['result_summary' => "## Summary\n\nAdds export."]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'video_cut', 'role' => 'cut', 'filename' => 'walkthrough.mp4', 'disk_path' => "{$task->id}/walkthrough.mp4", 'size_bytes' => 1]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'a.png', 'disk_path' => "{$task->id}/screenshots/a.png", 'size_bytes' => 1, 'caption' => 'Export page']);

    $body = invokeBuildPrBody($task, []);

    expect($body)
        ->toContain(PullRequestBodySections::startMarker(PullRequestBodySections::DESCRIPTION) . "\n## Summary\n\nAdds export.\n" . PullRequestBodySections::endMarker(PullRequestBodySections::DESCRIPTION))
        ->toContain(PullRequestBodySections::startMarker(PullRequestBodySections::SCREENSHOTS))
        ->toContain('### Screenshots')
        ->toContain('_Export page_')
        ->toContain(PullRequestBodySections::endMarker(PullRequestBodySections::SCREENSHOTS));

    expect(strpos($body, WalkthroughPrSection::MARKER_START))
        ->toBeLessThan(strpos($body, PullRequestBodySections::startMarker(PullRequestBodySections::SCREENSHOTS)))
        ->and(strpos($body, PullRequestBodySections::startMarker(PullRequestBodySections::SCREENSHOTS)))
        ->toBeLessThan(strpos($body, PullRequestBodySections::startMarker(PullRequestBodySections::DESCRIPTION)));
});

it('omits the screenshots markers when there are no screenshots', function (): void {
    $task = YakTask::factory()->create();

    $body = invokeBuildPrBody($task, []);

    expect($body)->not->toContain(PullRequestBodySections::startMarker(PullRequestBodySections::SCREENSHOTS));
});

it('opens the body with the walkthrough, then the screenshots, then the description', function (): void {
    $task = YakTask::factory()->create(['result_summary' => 'What changed and why.']);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'video_cut', 'role' => 'cut', 'filename' => 'walkthrough.mp4', 'disk_path' => "{$task->id}/walkthrough.mp4", 'size_bytes' => 1]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'zip.png', 'disk_path' => "{$task->id}/screenshots/zip.png", 'size_bytes' => 1, 'caption' => 'New ZIP-level section']);

    $body = invokeBuildPrBody($task, []);

    expect($body)->toStartWith(WalkthroughPrSection::MARKER_START)
        ->and(strpos($body, '### Video walkthrough'))->toBeLessThan(strpos($body, '### Screenshots'))
        ->and(strpos($body, '### Screenshots'))->toBeLessThan(strpos($body, '**Source:**'))
        ->and(strpos($body, '**Source:**'))->toBeLessThan(strpos($body, 'What changed and why.'));
});
