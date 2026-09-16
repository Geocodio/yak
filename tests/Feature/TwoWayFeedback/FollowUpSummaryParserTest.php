<?php

use App\Services\FollowUpSummaryParser;

it('splits the two sections and keeps a rewritten description', function () {
    $output = <<<'MD'
## What changed in this run

- Switched the retry loop to exponential backoff
- Added a test for the cap

## PR description

## Summary

Adds CSV export with a retrying uploader.

## Changes

### Export
- **Backoff** -- retries use exponential backoff capped at five attempts
MD;

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toBe("- Switched the retry loop to exponential backoff\n- Added a test for the cap")
        ->and($parsed->description)->toStartWith('## Summary')
        ->and($parsed->description)->toContain('exponential backoff capped');
});

it('returns a null description for Unchanged in any casing or punctuation', function (string $marker) {
    $output = "## What changed in this run\n\n- Fixed a typo\n\n## PR description\n\n{$marker}\n";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toBe('- Fixed a typo')
        ->and($parsed->description)->toBeNull();
})->with(['Unchanged.', 'unchanged', 'UNCHANGED', 'Unchanged']);

it('returns a null description for Unchanged followed by a trailer sentence', function () {
    $output = "## What changed in this run\n\n- Fixed a typo\n\n## PR description\n\nUnchanged. The existing description still covers this.\n";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toBe('- Fixed a typo')
        ->and($parsed->description)->toBeNull();
});

it('parses CRLF line endings end to end', function () {
    $output = "## What changed in this run\r\n\r\n- Fixed a typo\r\n\r\n## PR description\r\n\r\n## Summary\r\n\r\nRewritten body.\r\n";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toBe('- Fixed a typo')
        ->and($parsed->description)->toBe("## Summary\r\n\r\nRewritten body.");
});

it('strips a stray marker the agent echoed back in either section', function () {
    $output = "## What changed in this run\n\n- Fixed a typo <!-- /yak:description -->\n\n## PR description\n\n## Summary\n\nBody <!-- yak:description --> text.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toBe('- Fixed a typo')
        ->and($parsed->description)->toBe("## Summary\n\nBody  text.");
});

it('returns a null description when the section is empty', function () {
    $parsed = (new FollowUpSummaryParser)->parse("## What changed in this run\n\n- x\n\n## PR description\n\n   \n");

    expect($parsed->description)->toBeNull();
});

it('treats output without the headings as changes only', function () {
    $parsed = (new FollowUpSummaryParser)->parse("Addressed the feedback.\n\nRenamed the column.");

    expect($parsed->changes)->toBe("Addressed the feedback.\n\nRenamed the column.")
        ->and($parsed->description)->toBeNull();
});

it('matches headings case-insensitively and with trailing whitespace', function () {
    $parsed = (new FollowUpSummaryParser)->parse("## what changed in this run  \n\n- y\n\n## pr Description\n\nNew text");

    expect($parsed->changes)->toBe('- y')
        ->and($parsed->description)->toBe('New text');
});

it('strips an untagged Replies heading but keeps its prose in changes', function () {
    $output = "## What changed in this run\n\n- z\n\n## Replies\n\n> why a queue?\n\nBecause the upload is slow.\n\n## PR description\n\nUnchanged.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toContain('Because the upload is slow.')
        ->and($parsed->changes)->not->toContain('## Replies')
        ->and($parsed->description)->toBeNull();
});

it('extracts tagged replies and removes the section from changes', function () {
    $output = <<<'MD'
## What changed in this run

- Switched to exponential backoff

## Replies

- [c:101] Done in a1b2c3d: the loop now caps at five attempts.
- [c:102] Because the upload can take minutes; a sync call would block the request.
  See `UploadJob::handle()` for the timeout.

## PR description

Unchanged.
MD;

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->replies)->toBe([
        101 => 'Done in a1b2c3d: the loop now caps at five attempts.',
        102 => "Because the upload can take minutes; a sync call would block the request.\nSee `UploadJob::handle()` for the timeout.",
    ])
        ->and($parsed->changes)->toBe('- Switched to exponential backoff')
        ->and($parsed->description)->toBeNull();
});

it('keeps untagged text from the replies section in changes', function () {
    $output = "## What changed in this run\n\n- x\n\n## Replies\n\nGeneral note for the reviewer.\n\n- [c:5] Fixed.\n\n## PR description\n\nUnchanged.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->replies)->toBe([5 => 'Fixed.'])
        ->and($parsed->changes)->toBe("- x\n\nGeneral note for the reviewer.");
});

it('returns no replies when the section is absent', function () {
    $parsed = (new FollowUpSummaryParser)->parse("## What changed in this run\n\n- x\n\n## PR description\n\nUnchanged.");

    expect($parsed->replies)->toBe([])
        ->and($parsed->changes)->toBe('- x');
});

it('extracts replies even when the PR description heading is missing', function () {
    $parsed = (new FollowUpSummaryParser)->parse("## What changed in this run\n\n- x\n\n## Replies\n\n- [c:9] Answered.");

    expect($parsed->replies)->toBe([9 => 'Answered.'])
        ->and($parsed->changes)->toBe('- x');
});

it('takes the last entry when the same comment id is answered twice', function () {
    $parsed = (new FollowUpSummaryParser)->parse("## What changed in this run\n\n- x\n\n## Replies\n\n- [c:9] First.\n- [c:9] Second.\n\n## PR description\n\nUnchanged.");

    expect($parsed->replies)->toBe([9 => 'Second.']);
});

it('strips echoed markers even when the headings are missing', function () {
    $parsed = (new FollowUpSummaryParser)->parse("Addressed it.\n<!-- /yak:description -->");

    expect($parsed->changes)->toBe('Addressed it.');
});

it('sweeps tagged entries out from under a bare ### Replies heading', function () {
    $output = "## What changed in this run\n\n- x\n\n### Replies\n\n- [c:5] Fixed.\n\n## PR description\n\nUnchanged.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->replies)->toBe([5 => 'Fixed.'])
        ->and($parsed->changes)->toBe('- x')
        ->and($parsed->changes)->not->toContain('[c:');
});

it('sweeps tagged entries out from under a bare **Replies** heading', function () {
    $output = "## What changed in this run\n\n- x\n\n**Replies**\n\n- [c:5] Fixed.\n\n## PR description\n\nUnchanged.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->replies)->toBe([5 => 'Fixed.'])
        ->and($parsed->changes)->toBe('- x')
        ->and($parsed->changes)->not->toContain('[c:');
});

it('sweeps tagged entries with no Replies heading at all', function () {
    $output = "## What changed in this run\n\n- [c:9] Answered.\n\n## PR description\n\nUnchanged.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->replies)->toBe([9 => 'Answered.'])
        ->and($parsed->changes)->not->toContain('[c:');
});

it('bounds a Replies section placed before What changed at the next heading', function () {
    $output = "## Replies\n\n- [c:5] Fixed.\n\n## What changed in this run\n\n- x\n\n## PR description\n\nSomething";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toBe('- x')
        ->and($parsed->replies)->toBe([5 => 'Fixed.']);
});
