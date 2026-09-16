<?php

use App\Services\ReviewFeedbackFormatter;

it('renders the legacy batch header when no review state or reviewer is given', function () {
    $text = (new ReviewFeedbackFormatter)->format('', '', [
        ['id' => null, 'body' => 'handle empty state', 'author' => null, 'file' => null, 'line' => null, 'diff_hunk' => null],
        ['id' => null, 'body' => 'rename the column', 'author' => null, 'file' => 'app/Report.php', 'line' => 42, 'diff_hunk' => '@@ -1 +1 @@'],
    ], '');

    expect($text)->toBe(implode("\n", [
        'The following feedback was left on the pull request:',
        '',
        '- handle empty state',
        '- app/Report.php:42 — rename the column',
        '',
        '  ```diff',
        '  @@ -1 +1 @@',
        '  ```',
    ]));
});

it('renders a review header, quoted summary and inline comments', function () {
    $text = (new ReviewFeedbackFormatter)->format('changes_requested', "Retry loop is wrong.\nPlease fix.", [
        ['id' => null, 'body' => 'off by one', 'author' => 'alice', 'file' => 'app/Foo.php', 'line' => 7, 'diff_hunk' => null],
    ], 'alice');

    expect($text)->toBe(implode("\n", [
        '@alice submitted a review (changes requested):',
        '',
        '> Retry loop is wrong.',
        '> Please fix.',
        '',
        'Inline comments:',
        '',
        '- app/Foo.php:7 — off by one',
    ]));
});

it('omits the summary block and the inline heading when either is empty', function () {
    $summaryOnly = (new ReviewFeedbackFormatter)->format('commented', 'Looks fine overall.', [], 'bob');
    $inlineOnly = (new ReviewFeedbackFormatter)->format('approved', '', [
        ['id' => null, 'body' => 'nit: spacing', 'author' => 'bob', 'file' => 'a.php', 'line' => null, 'diff_hunk' => null],
    ], 'bob');

    expect($summaryOnly)->toBe("@bob submitted a review (commented):\n\n> Looks fine overall.")
        ->and($inlineOnly)->toBe("@bob submitted a review (approved):\n\nInline comments:\n\n- a.php — nit: spacing");
});

it('tags an inline comment with its github id so the agent can reply on its thread', function () {
    $text = (new ReviewFeedbackFormatter)->format('commented', '', [
        ['id' => 12345, 'body' => 'why a queue here?', 'author' => 'alice', 'file' => 'app/Foo.php', 'line' => 7, 'diff_hunk' => null],
        ['id' => null, 'body' => 'general remark', 'author' => 'alice', 'file' => null, 'line' => null, 'diff_hunk' => null],
    ], 'alice');

    expect($text)->toContain('- [c:12345] app/Foo.php:7 — why a queue here?')
        ->and($text)->toContain("\n- general remark")
        ->and($text)->not->toContain('[c:] general remark');
});
