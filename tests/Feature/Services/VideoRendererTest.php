<?php

use App\Services\VideoRenderer;
use Illuminate\Support\Facades\Process;

test('render spawns remotion render with the correct props', function () {
    Process::fake([
        '*remotion*render*' => Process::result(output: 'Rendered', errorOutput: '', exitCode: 0),
    ]);

    $renderer = new VideoRenderer(videoDir: base_path('video'));
    $outputPath = storage_path('artifacts/T1/reviewer-cut.mp4');
    $webmPath = storage_path('artifacts/T1/walkthrough.webm');
    $storyboardPath = storage_path('artifacts/T1/storyboard.json');

    @mkdir(dirname($outputPath), 0755, true);
    file_put_contents($webmPath, 'fake');
    file_put_contents($storyboardPath, json_encode(['version' => 1, 'plan' => (object) [], 'events' => []]));

    $renderer->render(
        webmPath: $webmPath,
        storyboardPath: $storyboardPath,
        outputPath: $outputPath,
        tier: 'reviewer',
    );

    Process::assertRan(function ($process) use ($outputPath) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'npx')
            && str_contains($command, 'remotion')
            && str_contains($command, 'render')
            && str_contains($command, 'Walkthrough')
            && str_contains($command, $outputPath);
    });

    @unlink($webmPath);
    @unlink($storyboardPath);
});

test('render stages the webm under the configured staging path, not video/public', function () {
    $staging = storage_path('framework/testing/render-staging');
    config()->set('yak.video.render_staging_path', $staging);

    Process::fake([
        '*remotion*render*' => Process::result(output: 'Rendered', errorOutput: '', exitCode: 0),
    ]);

    $renderer = new VideoRenderer(videoDir: base_path('video'));
    $outputPath = storage_path('artifacts/T2/reviewer-cut.mp4');
    $webmPath = storage_path('artifacts/T2/walkthrough.webm');
    $storyboardPath = storage_path('artifacts/T2/storyboard.json');
    @mkdir(dirname($outputPath), 0755, true);
    file_put_contents($webmPath, 'fake');
    file_put_contents($storyboardPath, json_encode(['version' => 1, 'plan' => (object) [], 'events' => []]));

    $renderer->render(webmPath: $webmPath, storyboardPath: $storyboardPath, outputPath: $outputPath);

    Process::assertRan(function ($process) use ($staging) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, '--public-dir=' . $staging . '/')
            && ! str_contains($command, base_path('video/public'));
    });

    expect(glob($staging . '/*/*.webm'))->toBe([]); // staged file cleaned up
    expect(is_dir(base_path('video/public')) ? glob(base_path('video/public/_render-*')) : [])->toBe([]);

    @unlink($webmPath);
    @unlink($storyboardPath);
});

test('probeDurationSeconds is public and returns null for unreadable input', function () {
    Process::fake(['*ffprobe*' => Process::result(output: '', errorOutput: 'nope', exitCode: 1)]);

    expect((new VideoRenderer(videoDir: base_path('video')))->probeDurationSeconds('/nope.webm'))->toBeNull();
});

test('render raises when remotion exits non-zero', function () {
    Process::fake([
        '*remotion*render*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $renderer = new VideoRenderer(videoDir: base_path('video'));
    $outputPath = storage_path('artifacts/T2/reviewer-cut.mp4');
    $webmPath = storage_path('artifacts/T2/walkthrough.webm');
    $storyboardPath = storage_path('artifacts/T2/storyboard.json');

    @mkdir(dirname($outputPath), 0755, true);
    file_put_contents($webmPath, 'fake');
    file_put_contents($storyboardPath, json_encode(['version' => 1, 'plan' => (object) [], 'events' => []]));

    expect(fn () => $renderer->render(
        webmPath: $webmPath,
        storyboardPath: $storyboardPath,
        outputPath: $outputPath,
        tier: 'reviewer',
    ))->toThrow(RuntimeException::class, 'Remotion render failed');

    @unlink($webmPath);
    @unlink($storyboardPath);
});
