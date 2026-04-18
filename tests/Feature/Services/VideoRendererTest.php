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
