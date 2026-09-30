<?php

use App\Agents\ClaudeCodeOutputParser;

function wrongRepositoryOutput(string $resultText): string
{
    return (string) json_encode([
        'session_id' => 's1',
        'result' => $resultText,
        'is_error' => false,
        'total_cost_usd' => 0.1,
    ]);
}

it('extracts a wrong_repository block into the run result', function () {
    $text = <<<'TXT'
This issue belongs to a different repository.

```wrong_repository
{"reason": "There is no billing code here.", "suggested_repository": "Geocodio/billing"}
```
TXT;

    $result = ClaudeCodeOutputParser::parse(wrongRepositoryOutput($text));

    expect($result->wrongRepository)->toBeTrue()
        ->and($result->wrongRepositoryReason)->toBe('There is no billing code here.')
        ->and($result->suggestedRepository)->toBe('Geocodio/billing');
});

it('treats a null suggestion as no suggestion', function () {
    $text = "```wrong_repository\n{\"reason\": \"Not here.\", \"suggested_repository\": null}\n```";

    $result = ClaudeCodeOutputParser::parse(wrongRepositoryOutput($text));

    expect($result->wrongRepository)->toBeTrue()
        ->and($result->suggestedRepository)->toBeNull();
});

it('does not flag runs without a wrong_repository block or with malformed JSON', function () {
    expect(ClaudeCodeOutputParser::parse(wrongRepositoryOutput('All done.'))->wrongRepository)->toBeFalse()
        ->and(ClaudeCodeOutputParser::parse(wrongRepositoryOutput("```wrong_repository\nnot json\n```"))->wrongRepository)->toBeFalse();
});
