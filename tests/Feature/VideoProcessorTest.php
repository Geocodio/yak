<?php

use App\Services\VideoProcessor;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Unit tests (Process::fake)
|--------------------------------------------------------------------------
*/

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
        'ffmpeg -filters*' => Process::result('T.C drawtext V->V Draw text'),
        '*ffmpeg -y*' => Process::result(''),
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
        'ffmpeg -filters*' => Process::result('T.C drawtext V->V Draw text'),
        '*ffmpeg -y*' => Process::result(output: '', exitCode: 1),
    ]);

    $result = VideoProcessor::process($tmpFile);

    expect($result)->toBe($tmpFile)
        ->and(file_get_contents($tmpFile))->toBe('original-content');

    @unlink($tmpFile);
});

/*
|--------------------------------------------------------------------------
| End-to-end test (real ffmpeg)
|--------------------------------------------------------------------------
*/

test('processes real walkthrough video end to end', function () {
    $fixture = __DIR__ . '/../Fixtures/walkthrough.webm';

    if (! file_exists($fixture)) {
        $this->markTestSkipped('Fixture walkthrough.webm not found');
    }

    // Work on a copy so the fixture isn't modified
    $tmpFile = tempnam(sys_get_temp_dir(), 'yak_e2e_') . '.webm';
    copy($fixture, $tmpFile);

    $originalSize = filesize($tmpFile);

    $result = VideoProcessor::process($tmpFile);

    expect($result)->toBe($tmpFile);

    $processedSize = filesize($tmpFile);

    // The processed video should exist and be valid
    expect($processedSize)->toBeGreaterThan(0);

    // The original is 140s with large idle gaps — processed should be significantly smaller
    expect($processedSize)->toBeLessThan($originalSize);

    // Verify the processed file is a valid video with ffprobe
    $durationResult = Process::run(
        sprintf('ffprobe -v quiet -show_entries format=duration -of csv=p=0 %s', escapeshellarg($tmpFile)),
    );
    expect($durationResult->successful())->toBeTrue();

    $processedDuration = (float) trim($durationResult->output());

    // Original is 140s. With 68s idle gap sped up 8x and trailing idle sped up, should be much shorter.
    expect($processedDuration)->toBeGreaterThan(5.0)
        ->and($processedDuration)->toBeLessThan(60.0);

    // Verify the file is a valid webm container
    $formatResult = Process::run(
        sprintf('ffprobe -v quiet -show_entries format=format_name -of csv=p=0 %s', escapeshellarg($tmpFile)),
    );
    expect(trim($formatResult->output()))->toContain('matroska');

    @unlink($tmpFile);
});
