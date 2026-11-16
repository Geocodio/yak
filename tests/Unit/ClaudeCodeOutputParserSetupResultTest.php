<?php

use App\Agents\ClaudeCodeOutputParser;

it('returns the reason from a failed setup_result block', function () {
    $text = "Notes.\n\n```setup_result\n{\"status\": \"failed\", \"reason\": \"The image build needs a token.\"}\n```";

    expect(ClaudeCodeOutputParser::extractSetupFailure($text))->toBe('The image build needs a token.');
});

it('treats ready, missing and malformed blocks as no failure', function (string $text) {
    expect(ClaudeCodeOutputParser::extractSetupFailure($text))->toBeNull();
})->with([
    'ready' => "```setup_result\n{\"status\": \"ready\"}\n```",
    'missing' => 'All set up.',
    'malformed' => "```setup_result\nnot json\n```",
]);

it('uses the last block and a fallback reason when none is given', function () {
    $text = "```setup_result\n{\"status\": \"ready\"}\n```\n\nLater:\n```setup_result\n{\"status\": \"failed\"}\n```";

    expect(ClaudeCodeOutputParser::extractSetupFailure($text))->toBe('The setup agent reported that it could not finish.');
});
