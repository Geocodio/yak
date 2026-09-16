<?php

use App\Services\PullRequestBodySections;
use App\Services\WalkthroughPrSection;

it('wraps content in named markers', function () {
    $wrapped = PullRequestBodySections::wrap(PullRequestBodySections::DESCRIPTION, "## Summary\n\nHello");

    expect($wrapped)->toBe("<!-- yak:description -->\n## Summary\n\nHello\n<!-- /yak:description -->");
});

it('detects whether a body has a named section', function () {
    $body = "intro\n\n" . PullRequestBodySections::wrap(PullRequestBodySections::SCREENSHOTS, 'shots') . "\n\nouttro";

    expect(PullRequestBodySections::has($body, PullRequestBodySections::SCREENSHOTS))->toBeTrue()
        ->and(PullRequestBodySections::has($body, PullRequestBodySections::DESCRIPTION))->toBeFalse();
});

it('replaces only the named section and leaves the rest alone', function () {
    $body = implode("\n\n", [
        '**Source:** github',
        PullRequestBodySections::wrap(PullRequestBodySections::DESCRIPTION, 'old description'),
        PullRequestBodySections::wrap(PullRequestBodySections::SCREENSHOTS, 'old shots'),
        'a human note',
    ]);

    $updated = PullRequestBodySections::replace(
        $body,
        PullRequestBodySections::DESCRIPTION,
        PullRequestBodySections::wrap(PullRequestBodySections::DESCRIPTION, 'new description'),
    );

    expect($updated)->toContain('new description')
        ->not->toContain('old description')
        ->toContain('old shots')
        ->toContain('**Source:** github')
        ->toContain('a human note');
});

it('returns the body unchanged when the markers are missing', function () {
    $body = "## Summary\n\nLegacy body with no markers";

    $updated = PullRequestBodySections::replace($body, PullRequestBodySections::DESCRIPTION, 'anything');

    expect($updated)->toBe($body);
});

it('does not treat a dollar sign in the replacement as a backreference', function () {
    $body = PullRequestBodySections::wrap(PullRequestBodySections::DESCRIPTION, 'old');

    $updated = PullRequestBodySections::replace(
        $body,
        PullRequestBodySections::DESCRIPTION,
        PullRequestBodySections::wrap(PullRequestBodySections::DESCRIPTION, 'costs $1 per call'),
    );

    expect($updated)->toContain('costs $1 per call');
});

it('keeps the walkthrough markers identical to the legacy constants', function () {
    expect(PullRequestBodySections::startMarker(PullRequestBodySections::WALKTHROUGH))->toBe(WalkthroughPrSection::MARKER_START)
        ->and(PullRequestBodySections::endMarker(PullRequestBodySections::WALKTHROUGH))->toBe(WalkthroughPrSection::MARKER_END);
});
