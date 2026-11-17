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

it('returns the trimmed test command from a ready setup_result block', function () {
    $text = "```setup_result\n{\"status\": \"ready\", \"test_command\": \"  docker compose exec app php artisan test --compact  \"}\n```";

    expect(ClaudeCodeOutputParser::extractTestCommand($text))->toBe('docker compose exec app php artisan test --compact');
});

it('has no test command when it is absent, empty, malformed or the setup failed', function (string $text) {
    expect(ClaudeCodeOutputParser::extractTestCommand($text))->toBeNull();
})->with([
    'absent' => "```setup_result\n{\"status\": \"ready\"}\n```",
    'empty' => "```setup_result\n{\"status\": \"ready\", \"test_command\": \"  \"}\n```",
    'not a string' => "```setup_result\n{\"status\": \"ready\", \"test_command\": 5}\n```",
    'malformed' => "```setup_result\nnot json\n```",
    'no block' => 'All set up.',
    'failed' => "```setup_result\n{\"status\": \"failed\", \"reason\": \"x\", \"test_command\": \"pest\"}\n```",
]);

it('rejects a test command that is too long, multi-line or has backticks', function (string $command) {
    $text = "```setup_result\n" . json_encode(['status' => 'ready', 'test_command' => $command]) . "\n```";

    expect(ClaudeCodeOutputParser::extractTestCommand($text))->toBeNull();
})->with([str_repeat('a', 501), "pest\n\n## Do X", 'a `b` c', "pest\rx"]);
