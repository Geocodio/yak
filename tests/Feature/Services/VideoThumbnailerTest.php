<?php

use App\Services\VideoThumbnailer;
use Illuminate\Support\Facades\Process;

test('generate spawns ffmpeg with overlay composite filter', function () {
    Process::fake([
        '*ffmpeg*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);

    $video = tempnam(sys_get_temp_dir(), 'vid') . '.mp4';
    file_put_contents($video, 'fake');
    $overlay = tempnam(sys_get_temp_dir(), 'pb') . '.png';
    file_put_contents($overlay, 'fake');
    $output = sys_get_temp_dir() . '/thumbnailer-test-' . uniqid() . '.jpg';

    try {
        (new VideoThumbnailer($overlay))->generate($video, $output);

        Process::assertRan(function ($process) use ($video, $overlay, $output): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, 'ffmpeg')
                && str_contains($command, $video)
                && str_contains($command, $overlay)
                && str_contains($command, $output)
                && str_contains($command, 'overlay=(W-w)/2:(H-h)/2');
        });
    } finally {
        @unlink($video);
        @unlink($overlay);
        @unlink($output);
    }
});

test('generate throws when ffmpeg exits non-zero', function () {
    Process::fake([
        '*ffmpeg*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $video = tempnam(sys_get_temp_dir(), 'vid') . '.mp4';
    file_put_contents($video, 'fake');
    $overlay = tempnam(sys_get_temp_dir(), 'pb') . '.png';
    file_put_contents($overlay, 'fake');
    $output = sys_get_temp_dir() . '/thumbnailer-test-' . uniqid() . '.jpg';

    try {
        expect(fn () => (new VideoThumbnailer($overlay))->generate($video, $output))
            ->toThrow(RuntimeException::class, 'ffmpeg thumbnail generation failed');
    } finally {
        @unlink($video);
        @unlink($overlay);
    }
});

test('generate throws when the source video is missing', function () {
    $overlay = tempnam(sys_get_temp_dir(), 'pb') . '.png';
    file_put_contents($overlay, 'fake');

    try {
        expect(fn () => (new VideoThumbnailer($overlay))->generate('/no/such/video.mp4', '/tmp/out.jpg'))
            ->toThrow(RuntimeException::class, 'video not found');
    } finally {
        @unlink($overlay);
    }
});

test('generate throws when the play overlay is missing', function () {
    $video = tempnam(sys_get_temp_dir(), 'vid') . '.mp4';
    file_put_contents($video, 'fake');

    try {
        expect(fn () => (new VideoThumbnailer('/no/such/overlay.png'))->generate($video, '/tmp/out.jpg'))
            ->toThrow(RuntimeException::class, 'play overlay not found');
    } finally {
        @unlink($video);
    }
});
