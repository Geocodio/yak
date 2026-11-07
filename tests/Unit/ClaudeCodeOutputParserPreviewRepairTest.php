<?php

use App\Agents\ClaudeCodeOutputParser;
use App\Enums\PreviewRepairVerdict;

it('parses a recipe verdict', function () {
    $text = "I fixed the recipe.\n\n```preview_repair\n{\"verdict\": \"recipe\", \"cause\": \"The Dockerfile was removed.\", \"introduced_by\": \"d6dd223\"}\n```";

    expect(ClaudeCodeOutputParser::extractPreviewRepairVerdict($text))->toBe([
        'verdict' => PreviewRepairVerdict::Recipe,
        'cause' => 'The Dockerfile was removed.',
        'introduced_by' => 'd6dd223',
    ]);
});

it('treats a missing block as unknown', function () {
    expect(ClaudeCodeOutputParser::extractPreviewRepairVerdict('No block here.'))->toBe([
        'verdict' => PreviewRepairVerdict::Unknown,
        'cause' => '',
        'introduced_by' => null,
    ]);
});

it('treats an unknown verdict value as unknown', function () {
    $text = "```preview_repair\n{\"verdict\": \"pending\", \"cause\": \"x\"}\n```";

    expect(ClaudeCodeOutputParser::extractPreviewRepairVerdict($text)['verdict'])->toBe(PreviewRepairVerdict::Unknown);
});

it('treats bad json as unknown', function () {
    $text = "```preview_repair\n{verdict: recipe\n```";

    expect(ClaudeCodeOutputParser::extractPreviewRepairVerdict($text)['verdict'])->toBe(PreviewRepairVerdict::Unknown);
});
