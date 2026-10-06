<?php

use App\Support\ReviewSuggestion;

/**
 * @param  array<int, int>  $lines
 * @return array<int, true>
 */
function commentable(array $lines): array
{
    return array_fill_keys($lines, true);
}

function suggestionBody(string $original, string $suggestion): string
{
    return "Fix it.\n\n```original\n{$original}\n```\n```suggestion\n{$suggestion}\n```";
}

it('anchors a multi-line replacement to where the original lines are', function () {
    $file = "a\nb\nc\nd\n";
    $body = suggestionBody("b\nc", "B\nC\nC2");

    $result = ReviewSuggestion::resolve($body, 3, $file, commentable([1, 2, 3, 4]), $body);

    expect($result->isSuggestion)->toBeTrue()
        ->and($result->startLine)->toBe(2)
        ->and($result->line)->toBe(3)
        ->and($result->body)->toBe("Fix it.\n\n```suggestion\nB\nC\nC2\n```");
});

it('uses a single-line anchor when the original is one line', function () {
    $file = "a\nb\nc\n";
    $body = suggestionBody('b', "b\nb2");

    $result = ReviewSuggestion::resolve($body, 9, $file, commentable([2]), $body);

    expect($result->isSuggestion)->toBeTrue()
        ->and($result->startLine)->toBeNull()
        ->and($result->line)->toBe(2);
});

it('picks the match containing the reported line when the original repeats', function () {
    $file = "x\n}\ny\n}\n";
    $body = suggestionBody('}', '};');

    $result = ReviewSuggestion::resolve($body, 4, $file, commentable([1, 2, 3, 4]), $body);

    expect($result->line)->toBe(4);
});

it('omits the suggestion when the original repeats and the reported line matches none', function () {
    $file = "x\n}\ny\n}\n";
    $body = suggestionBody('}', '};');

    $result = ReviewSuggestion::resolve($body, 3, $file, commentable([1, 2, 3, 4]), $body);

    expect($result->isSuggestion)->toBeFalse()
        ->and($result->omittedReason)->toBe('original_not_found');
});

it('ignores trailing whitespace but not indentation when matching', function () {
    $file = "    foo();   \n";

    $trailing = suggestionBody('    foo();', '    bar();');
    $indented = suggestionBody('foo();', 'bar();');

    expect(ReviewSuggestion::resolve($trailing, 1, $file, commentable([1]), $trailing)->isSuggestion)->toBeTrue()
        ->and(ReviewSuggestion::resolve($indented, 1, $file, commentable([1]), $indented)->omittedReason)->toBe('original_not_found');
});

it('omits the suggestion and keeps the prose when it cannot be verified', function (string $body, ?string $file, array $lines, ?string $agentOutput, string $reason) {
    $result = ReviewSuggestion::resolve($body, 2, $file, commentable($lines), $agentOutput ?? $body);

    expect($result->isSuggestion)->toBeFalse()
        ->and($result->omittedReason)->toBe($reason)
        ->and($result->startLine)->toBeNull()
        ->and($result->line)->toBe(2)
        ->and($result->body)->toStartWith('Fix it.')
        ->and($result->body)->toEndWith(ReviewSuggestion::OMITTED_NOTE)
        ->and($result->body)->not->toContain('```');
})->with([
    'no original fence' => ["Fix it.\n\n```suggestion\nB\n```", "a\nb\n", [1, 2], null, 'no_original_fence'],
    'original not in file' => [suggestionBody('zzz', 'B'), "a\nb\n", [1, 2], null, 'original_not_found'],
    'file unreadable' => [suggestionBody('b', 'B'), null, [1, 2], null, 'file_unreadable'],
    'range leaves the diff' => [suggestionBody("a\nb", "A\nB"), "a\nb\n", [2], null, 'outside_diff'],
    'structurer altered the suggestion' => [suggestionBody('b', '  B'), "a\nb\n", [1, 2], suggestionBody('b', 'B'), 'suggestion_not_verbatim'],
    'no-op' => [suggestionBody('b', 'b'), "a\nb\n", [1, 2], null, 'no_op'],
]);

it('allows a suggestion that deletes the original lines', function () {
    $file = "a\nb\nc\n";
    $body = "Drop it.\n\n```original\nb\n```\n```suggestion\n```";

    $result = ReviewSuggestion::resolve($body, 2, $file, commentable([1, 2, 3]), $body);

    expect($result->isSuggestion)->toBeTrue()
        ->and($result->line)->toBe(2)
        ->and($result->body)->toBe("Drop it.\n\n```suggestion\n```");
});

it('leaves a finding without a suggestion untouched', function () {
    $result = ReviewSuggestion::resolve('Just prose.', 7, null, [], '');

    expect($result->isSuggestion)->toBeFalse()
        ->and($result->omittedReason)->toBeNull()
        ->and($result->line)->toBe(7)
        ->and($result->body)->toBe('Just prose.');
});
