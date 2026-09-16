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

it('keeps a Replies section inside changes', function () {
    $output = "## What changed in this run\n\n- z\n\n## Replies\n\n> why a queue?\n\nBecause the upload is slow.\n\n## PR description\n\nUnchanged.";

    $parsed = (new FollowUpSummaryParser)->parse($output);

    expect($parsed->changes)->toContain('## Replies')
        ->and($parsed->changes)->toContain('Because the upload is slow.')
        ->and($parsed->description)->toBeNull();
});
