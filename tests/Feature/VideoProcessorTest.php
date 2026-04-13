<?php

use App\Services\VideoProcessor;
use Illuminate\Support\Facades\Process;

test('returns original path when file does not exist', function () {
    $result = VideoProcessor::process('/nonexistent/video.webm');

    expect($result)->toBe('/nonexistent/video.webm');
});

test('returns original path when video is too short', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'yak_test_') . '.webm';
    file_put_contents($tmpFile, 'fake-video-content');

    Process::fake([
        '*ffprobe*format=duration*' => Process::result('2.5'),
    ]);

    $result = VideoProcessor::process($tmpFile);

    expect($result)->toBe($tmpFile);

    @unlink($tmpFile);
});

test('returns original path when scene detection finds no changes', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'yak_test_') . '.webm';
    file_put_contents($tmpFile, 'fake-video-content');

    Process::fake([
        '*ffprobe*format=duration*' => Process::result('30.0'),
        '*ffprobe*select=gt*' => Process::result(''),
    ]);

    $result = VideoProcessor::process($tmpFile);

    expect($result)->toBe($tmpFile);

    @unlink($tmpFile);
});

test('processes video with idle segments', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'yak_test_') . '.webm';
    file_put_contents($tmpFile, 'fake-video-content');

    // Scene changes: activity at 2s and 3s, then idle gap, then activity at 15s
    $sceneOutput = "2.0\n3.0\n15.0\n16.0\n";

    Process::fake([
        '*ffprobe*format=duration*' => Process::result('20.0'),
        '*ffprobe*select=gt*' => Process::result($sceneOutput),
        '*ffmpeg*' => Process::result(''),
    ]);

    // Create a fake "processed" output file so rename succeeds
    $processedPath = str_replace('.webm', '_processed.webm', $tmpFile);
    file_put_contents($processedPath, 'processed-video-content');

    $result = VideoProcessor::process($tmpFile);

    expect($result)->toBe($tmpFile);

    // Verify ffmpeg was called with speed and drawtext filters
    Process::assertRan(function ($process) {
        $command = $process->command;
        if (is_array($command)) {
            $command = implode(' ', $command);
        }

        return str_contains($command, 'ffmpeg')
            && str_contains($command, 'drawtext')
            && str_contains($command, 'setpts');
    });

    @unlink($tmpFile);
    @unlink($processedPath);
});

test('returns original path when ffmpeg fails', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'yak_test_') . '.webm';
    file_put_contents($tmpFile, 'original-content');

    $sceneOutput = "2.0\n3.0\n15.0\n16.0\n";

    Process::fake([
        '*ffprobe*format=duration*' => Process::result('20.0'),
        '*ffprobe*select=gt*' => Process::result($sceneOutput),
        '*ffmpeg*' => Process::result(output: '', exitCode: 1),
    ]);

    $result = VideoProcessor::process($tmpFile);

    expect($result)->toBe($tmpFile)
        ->and(file_get_contents($tmpFile))->toBe('original-content');

    @unlink($tmpFile);
});
