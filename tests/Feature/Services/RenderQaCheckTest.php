<?php

use App\DataTransferObjects\WalkthroughTimeline;
use App\Services\RenderQaCheck;
use App\Services\RenderQaFailure;
use App\Services\VideoRenderer;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

function ffmpegAvailable(): bool
{
    return (new ExecutableFinder)->find('ffmpeg') !== null;
}

/** 20 s of a single flat colour: every sampled frame hashes the same. */
function makeSolidColourMp4(): string
{
    $path = sys_get_temp_dir() . '/qa-solid-' . bin2hex(random_bytes(4)) . '.mp4';
    Process::run([
        'ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=0x3d4f5f:s=320x180:d=20:r=15',
        '-pix_fmt', 'yuv420p', $path,
    ]);

    return $path;
}

/** 20 s of moving test bars: sampled frames differ. */
function makeTwoToneMp4(): string
{
    $path = sys_get_temp_dir() . '/qa-bars-' . bin2hex(random_bytes(4)) . '.mp4';
    Process::run([
        'ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=s=320x180:d=20:r=15',
        '-pix_fmt', 'yuv420p', $path,
    ]);

    return $path;
}

/**
 * @param  array<int, array{shotId: string, width: float}>  $overflow
 */
function qaTimeline(float $duration = 40.0, array $overflow = [], int $shots = 2): WalkthroughTimeline
{
    $blocks = [];
    for ($i = 0; $i < $shots; $i++) {
        $blocks[] = ['kind' => 'shot', 'id' => "shot-{$i}", 'startSeconds' => 4.0 + $i * 8, 'durationSeconds' => 8.0];
    }

    return WalkthroughTimeline::fromJson((string) json_encode([
        'fps' => 30, 'width' => 1440, 'height' => 952,
        'durationSeconds' => $duration, 'durationInFrames' => (int) ($duration * 30),
        'blocks' => $blocks, 'chapters' => [], 'captionOverflow' => $overflow,
    ]));
}

it('rejects caption overflow', function (): void {
    $renderer = Mockery::mock(VideoRenderer::class);

    (new RenderQaCheck($renderer))->assertPasses('/tmp/x.mp4', qaTimeline(overflow: [
        ['shotId' => 'levels', 'width' => 3200.0],
    ]));
})->throws(RenderQaFailure::class, 'levels');

it('reports an overflow reason naming every shot', function (): void {
    expect(RenderQaCheck::overflowReason([]))->toBeNull();

    expect(RenderQaCheck::overflowReason([
        ['shotId' => 'levels', 'width' => 3200.0],
        ['shotId' => 'updates', 'width' => 2900.0],
    ]))->toBe('caption too long for its box: levels, updates');
});

it('reports a bounds reason only outside the configured bounds', function (): void {
    config()->set('yak.video.duration_bounds', [30, 180]);

    expect(RenderQaCheck::boundsReason(40.0))->toBeNull();
    expect(RenderQaCheck::boundsReason(12.0))->toBe('rendered video is 12.0s, outside the 30-180s bounds');
    expect(RenderQaCheck::boundsReason(240.0))->toBe('rendered video is 240.0s, outside the 30-180s bounds');
});

it('rejects a video whose duration cannot be probed', function (): void {
    $renderer = Mockery::mock(VideoRenderer::class);
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    (new RenderQaCheck($renderer))->assertPasses('/tmp/x.mp4', qaTimeline());
})->throws(RenderQaFailure::class, 'could not probe');

it('rejects a video outside the duration bounds', function (): void {
    config()->set('yak.video.duration_bounds', [30, 180]);
    $renderer = Mockery::mock(VideoRenderer::class);
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(12.0);

    (new RenderQaCheck($renderer))->assertPasses('/tmp/x.mp4', qaTimeline(duration: 12.0));
})->throws(RenderQaFailure::class, 'bounds');

it('rejects a video more than 10 percent off the timeline', function (): void {
    config()->set('yak.video.duration_bounds', [10, 180]);
    $renderer = Mockery::mock(VideoRenderer::class);
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    (new RenderQaCheck($renderer))->assertPasses('/tmp/x.mp4', qaTimeline(duration: 40.0));
})->throws(RenderQaFailure::class, 'timeline expected');

it('passes a real cut whose shots differ', function (): void {
    config()->set('yak.video.duration_bounds', [1, 180]);
    $mp4 = makeTwoToneMp4();
    $renderer = new VideoRenderer(base_path('video'));

    $timeline = qaTimeline(duration: (float) $renderer->probeDurationSeconds($mp4));

    expect(fn (): mixed => (new RenderQaCheck($renderer))->assertPasses($mp4, $timeline))
        ->not->toThrow(RenderQaFailure::class);

    @unlink($mp4);
})->skip(fn (): bool => ! ffmpegAvailable(), 'ffmpeg not installed');

it('rejects a static cut', function (): void {
    config()->set('yak.video.duration_bounds', [1, 180]);
    $mp4 = makeSolidColourMp4();
    $renderer = new VideoRenderer(base_path('video'));

    (new RenderQaCheck($renderer))->assertPasses($mp4, qaTimeline(duration: (float) $renderer->probeDurationSeconds($mp4)));
})->throws(RenderQaFailure::class, 'identical')->skip(fn (): bool => ! ffmpegAvailable(), 'ffmpeg not installed');
