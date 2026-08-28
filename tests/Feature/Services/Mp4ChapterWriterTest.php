<?php

use App\DataTransferObjects\WalkthroughTimeline;
use App\Services\Mp4ChapterWriter;
use Illuminate\Support\Facades\Process;

function chapterTimeline(): WalkthroughTimeline
{
    return WalkthroughTimeline::fromJson(json_encode([
        'fps' => 30, 'width' => 1440, 'height' => 952,
        'durationSeconds' => 80.0, 'durationInFrames' => 2400,
        'blocks' => [], 'captionOverflow' => [],
        'chapters' => [
            ['title' => 'Geography levels', 'startSeconds' => 4.0, 'shots' => []],
            ['title' => 'Published; final', 'startSeconds' => 31.0, 'shots' => []],
        ],
    ]));
}

it('builds an ffmetadata document with contiguous chapters', function (): void {
    $ffmeta = (new Mp4ChapterWriter)->ffmeta(chapterTimeline());

    expect($ffmeta)->toStartWith(';FFMETADATA1')
        ->toContain("START=4000\nEND=31000\ntitle=Geography levels")
        ->toContain("START=31000\nEND=80000\ntitle=Published\; final");
});

it('remuxes the metadata into the mp4', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    $mp4 = sys_get_temp_dir() . '/chapters-' . bin2hex(random_bytes(4)) . '.mp4';
    file_put_contents($mp4, 'mp4');
    // The writer renames its temp output over the original.
    Process::fake(function ($process) use ($mp4) {
        file_put_contents($mp4 . '.chapters.mp4', 'mp4-with-chapters');

        return Process::result('', '', 0);
    });

    (new Mp4ChapterWriter)->write($mp4, chapterTimeline());

    expect(file_get_contents($mp4))->toBe('mp4-with-chapters')
        ->and(file_exists($mp4 . '.chapters.mp4'))->toBeFalse();
});

it('does nothing without chapters', function (): void {
    Process::fake();
    $timeline = WalkthroughTimeline::fromJson(json_encode([
        'fps' => 30, 'width' => 1, 'height' => 1, 'durationSeconds' => 10.0,
        'durationInFrames' => 300, 'blocks' => [], 'chapters' => [], 'captionOverflow' => [],
    ]));

    (new Mp4ChapterWriter)->write('/tmp/none.mp4', $timeline);

    Process::assertNothingRan();
});
