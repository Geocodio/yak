<?php

use App\DataTransferObjects\WalkthroughTimeline;

function timelineJson(): string
{
    return json_encode([
        'fps' => 30,
        'width' => 1440,
        'height' => 952,
        'durationSeconds' => 42.5,
        'durationInFrames' => 1275,
        'blocks' => [
            ['kind' => 'title', 'id' => 'title', 'startSeconds' => 0.0, 'durationSeconds' => 4.0],
            ['kind' => 'chapter', 'id' => 'chapter-0', 'startSeconds' => 4.0, 'durationSeconds' => 2.0],
            ['kind' => 'shot', 'id' => 'levels', 'startSeconds' => 6.0, 'durationSeconds' => 8.0],
            ['kind' => 'shot', 'id' => 'updates', 'startSeconds' => 14.0, 'durationSeconds' => 6.0],
            ['kind' => 'summary', 'id' => 'summary', 'startSeconds' => 20.0, 'durationSeconds' => 6.0],
        ],
        'chapters' => [
            ['title' => 'Geography levels', 'startSeconds' => 4.0, 'shots' => [
                ['id' => 'levels', 'startSeconds' => 6.0, 'say' => 'Eleven levels.'],
            ]],
        ],
        'captionOverflow' => [],
    ], JSON_THROW_ON_ERROR);
}

it('parses the timeline json', function (): void {
    $timeline = WalkthroughTimeline::fromJson(timelineJson());

    expect($timeline->fps)->toBe(30)
        ->and($timeline->durationSeconds)->toBe(42.5)
        ->and($timeline->durationInFrames)->toBe(1275)
        ->and($timeline->chapters)->toHaveCount(1)
        ->and($timeline->captionOverflow)->toBe([]);
});

it('finds the first shot start', function (): void {
    expect(WalkthroughTimeline::fromJson(timelineJson())->firstShotStartSeconds())->toBe(6.0);
});

it('lists shot blocks and their mid-hold points', function (): void {
    $timeline = WalkthroughTimeline::fromJson(timelineJson());

    expect($timeline->shotBlocks())->toBe([
        ['id' => 'levels', 'startSeconds' => 6.0, 'durationSeconds' => 8.0],
        ['id' => 'updates', 'startSeconds' => 14.0, 'durationSeconds' => 6.0],
    ])->and($timeline->midHoldSeconds())->toBe([
        ['id' => 'levels', 'seconds' => 12.0],
        ['id' => 'updates', 'seconds' => 18.5],
    ]);
});

it('rejects malformed json', function (): void {
    WalkthroughTimeline::fromJson('not json');
})->throws(RuntimeException::class);
