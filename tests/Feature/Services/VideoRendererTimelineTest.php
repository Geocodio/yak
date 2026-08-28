<?php

use App\DataTransferObjects\WalkthroughTimeline;
use App\Services\VideoRenderer;
use Illuminate\Support\Facades\Process;

it('runs timeline.ts and parses its output', function (): void {
    Process::fake([
        '*timeline.ts*' => Process::result(json_encode([
            'fps' => 30, 'width' => 1440, 'height' => 952,
            'durationSeconds' => 40.0, 'durationInFrames' => 1200,
            'blocks' => [], 'chapters' => [], 'captionOverflow' => [],
        ]), '', 0),
    ]);

    $timeline = (new VideoRenderer(base_path('video')))
        ->timeline('/tmp/script.json', '/tmp/manifest.json');

    expect($timeline)->toBeInstanceOf(WalkthroughTimeline::class)
        ->and($timeline->durationSeconds)->toBe(40.0);

    Process::assertRan(fn ($process): bool => in_array('scripts/timeline.ts', $process->command, strict: true)
        && in_array('--script', $process->command, strict: true));
});

it('throws with stderr when timeline.ts fails', function (): void {
    Process::fake(['*' => Process::result('', 'shot "levels" has no clip', 2)]);

    (new VideoRenderer(base_path('video')))->timeline('/tmp/script.json', '/tmp/manifest.json');
})->throws(RuntimeException::class, 'shot "levels" has no clip');
