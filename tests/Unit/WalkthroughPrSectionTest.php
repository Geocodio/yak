<?php

use App\Services\WalkthroughPrSection;

it('formats timestamps as m:ss', function (): void {
    expect(WalkthroughPrSection::timestamp(4.0))->toBe('0:04')
        ->and(WalkthroughPrSection::timestamp(31.4))->toBe('0:31')
        ->and(WalkthroughPrSection::timestamp(84.0))->toBe('1:24')
        ->and(WalkthroughPrSection::timestamp(600.0))->toBe('10:00');
});

it('renders the pending placeholder inside markers', function (): void {
    expect(WalkthroughPrSection::pending())
        ->toStartWith(WalkthroughPrSection::MARKER_START)
        ->toEndWith(WalkthroughPrSection::MARKER_END)
        ->toContain('### Video walkthrough')
        ->toContain('_Rendering, this section will update automatically._');
});

it('renders the ready section with gif, link and chapters', function (): void {
    $section = WalkthroughPrSection::ready(
        videoUrl: 'https://yak.test/mp4',
        gifUrl: 'https://yak.test/gif',
        thumbnailUrl: 'https://yak.test/jpg',
        durationSeconds: 84.0,
        chapters: [
            ['title' => 'Geography levels', 'startSeconds' => 4.0, 'url' => 'https://yak.test/tasks/5?t=4'],
            ['title' => 'Published', 'startSeconds' => 31.0, 'url' => 'https://yak.test/tasks/5?t=31'],
        ],
    );

    expect($section)
        ->toContain('![walkthrough preview](https://yak.test/gif)')
        ->toContain('▶ [Watch the full walkthrough (1:24)](https://yak.test/mp4)')
        ->toContain('[0:04](https://yak.test/tasks/5?t=4) Geography levels · [0:31](https://yak.test/tasks/5?t=31) Published')
        ->not->toContain('yak.test/jpg');
});

it('falls back to the poster when there is no gif', function (): void {
    expect(WalkthroughPrSection::ready('https://v', null, 'https://t', 30.0, []))
        ->toContain('![walkthrough poster](https://t)');
});

it('renders the unavailable line', function (): void {
    expect(WalkthroughPrSection::unavailable("caption too long\n  for its box"))
        ->toContain('_Video walkthrough unavailable (render failed: caption too long for its box)._');
});

it('replaces the marked block wholesale', function (): void {
    $body = "Intro\n\n" . WalkthroughPrSection::pending() . "\n\n### Files changed\n\n- a.php";

    $updated = WalkthroughPrSection::replaceIn($body, WalkthroughPrSection::unavailable('boom'));

    expect($updated)
        ->toContain('### Files changed')
        ->toContain('render failed: boom')
        ->not->toContain('_Rendering,')
        ->and(substr_count($updated, WalkthroughPrSection::MARKER_START))->toBe(1);
});

it('keeps literal $-digit sequences in the replacement verbatim', function (): void {
    $body = "Intro\n\n" . WalkthroughPrSection::pending() . "\n\n### Files changed\n\n- a.php";

    $section = WalkthroughPrSection::ready(
        videoUrl: 'https://yak.test/mp4',
        gifUrl: null,
        thumbnailUrl: null,
        durationSeconds: 84.0,
        chapters: [
            ['title' => 'Add $1 and $5 discount button', 'startSeconds' => 4.0, 'url' => 'https://yak.test/tasks/5?amount=$2'],
        ],
    );

    $updated = WalkthroughPrSection::replaceIn($body, $section);

    expect($updated)
        ->toContain('Add $1 and $5 discount button')
        ->toContain('https://yak.test/tasks/5?amount=$2');
});

it('replaces a legacy unmarked section', function (): void {
    $body = "Intro\n\n### Video walkthrough\n\n- [walkthrough.mp4](https://old)\n\n### Files changed\n\n- a.php";

    $updated = WalkthroughPrSection::replaceIn($body, WalkthroughPrSection::pending());

    expect($updated)
        ->toContain('### Files changed')
        ->not->toContain('https://old')
        ->and(substr_count($updated, '### Video walkthrough'))->toBe(1);
});

it('appends when there is no section at all', function (): void {
    $updated = WalkthroughPrSection::replaceIn('Just a body', WalkthroughPrSection::pending());

    expect($updated)->toStartWith('Just a body')->toContain(WalkthroughPrSection::MARKER_START);
});

it('renders captioned screenshots', function (): void {
    expect(WalkthroughPrSection::screenshots([
        ['caption' => 'The new ZIP section', 'url' => 'https://a.png'],
        ['caption' => null, 'url' => 'https://b.png'],
    ]))
        ->toContain('![The new ZIP section](https://a.png)')
        ->toContain('_The new ZIP section_')
        ->toContain('![screenshot](https://b.png)');
});
