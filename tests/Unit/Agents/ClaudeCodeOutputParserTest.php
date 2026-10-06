<?php

use App\Agents\ClaudeCodeOutputParser;

it('parses a successful Claude Code JSON payload', function () {
    $json = json_encode([
        'result' => 'Fixed the bug successfully',
        'cost_usd' => 2.50,
        'session_id' => 'sess_success123',
        'num_turns' => 15,
        'duration_ms' => 120000,
        'is_error' => false,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->sessionId)->toBe('sess_success123')
        ->and($result->resultSummary)->toBe('Fixed the bug successfully')
        ->and($result->costUsd)->toBe(2.5)
        ->and($result->numTurns)->toBe(15)
        ->and($result->durationMs)->toBe(120000)
        ->and($result->isError)->toBeFalse()
        ->and($result->clarificationQuestions)->toBe([])
        ->and($result->rawOutput)->toBe($json);
});

it('parses an error payload with is_error true', function () {
    $json = json_encode([
        'result' => 'Budget exceeded',
        'session_id' => 'sess_3',
        'cost_usd' => 5.0,
        'num_turns' => 40,
        'duration_ms' => 600000,
        'is_error' => true,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toBe('Budget exceeded')
        ->and($result->costUsd)->toBe(5.0);
});

it('returns a failure result when output is not JSON', function () {
    $result = ClaudeCodeOutputParser::parse('this is not json');

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toBe('Claude Code returned malformed output')
        ->and($result->rawOutput)->toBe('this is not json')
        ->and($result->sessionId)->toBe('');
});

it('accepts result_summary as an alias for result', function () {
    $json = json_encode([
        'result_summary' => 'Alternate key',
        'session_id' => 'sess_4',
        'is_error' => false,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->resultSummary)->toBe('Alternate key');
});

it('defaults missing numeric fields to zero', function () {
    $json = json_encode([
        'result' => 'ok',
        'session_id' => 'sess_5',
        'is_error' => false,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->costUsd)->toBe(0.0)
        ->and($result->numTurns)->toBe(0)
        ->and($result->durationMs)->toBe(0);
});

function clarificationResult(string $text): string
{
    return json_encode(['result' => $text, 'session_id' => 'sess_q', 'cost_usd' => 0.1, 'num_turns' => 2, 'duration_ms' => 100]);
}

it('parses a fenced clarification block into questions', function () {
    $text = "I found two problems.\n\n```clarification\n" . json_encode(['questions' => [
        ['id' => 'payg', 'header' => 'PAYG trigger', 'question' => 'Only on rejection?', 'options' => [
            ['label' => 'Only on rejection', 'description' => 'Requests are blocked.'],
            ['label' => 'Also at 0', 'description' => 'Keep the issue trigger.'],
        ]],
        ['id' => 'demo', 'header' => 'Demo accounts', 'question' => 'Include demo?', 'multi_select' => true, 'options' => [
            ['label' => 'Leave out'], ['label' => 'Include'],
        ]],
    ]]) . "\n```";

    $result = ClaudeCodeOutputParser::parse(clarificationResult($text));

    expect($result->needsClarification())->toBeTrue()
        ->and($result->clarificationQuestions)->toHaveCount(2)
        ->and($result->clarificationQuestions[1]->multiSelect)->toBeTrue();
});

it('uses the last clarification block when there are several', function () {
    $block = fn (string $id) => "```clarification\n" . json_encode(['questions' => [
        ['id' => $id, 'question' => 'Q?', 'options' => [['label' => 'a'], ['label' => 'b']]],
    ]]) . "\n```";

    $result = ClaudeCodeOutputParser::parse(clarificationResult($block('first') . "\n\nRevised:\n" . $block('second')));

    expect($result->clarificationQuestions[0]->id)->toBe('second');
});

it('ignores the old flat clarification shapes', function (string $text) {
    expect(ClaudeCodeOutputParser::parse(clarificationResult($text))->needsClarification())->toBeFalse();
})->with([
    'snake case' => ["```json\n{\"clarification_needed\": true, \"options\": [\"A\", \"B\"]}\n```"],
    'camel case' => ["```json\n{\"clarificationNeeded\": true, \"clarificationOptions\": [\"A\", \"B\"]}\n```"],
]);

it('ignores the top-level clarification_needed key', function () {
    $output = json_encode(['result' => 'x', 'clarification_needed' => true, 'options' => ['A', 'B']]);

    expect(ClaudeCodeOutputParser::parse($output)->needsClarification())->toBeFalse();
});

it('treats malformed JSON in the block as no clarification', function () {
    $result = ClaudeCodeOutputParser::parse(clarificationResult("```clarification\n{not json\n```"));

    expect($result->needsClarification())->toBeFalse()
        ->and($result->isError)->toBeFalse();
});

it('drops invalid questions, duplicate ids, and caps at six', function () {
    $questions = [['id' => 'bad', 'question' => 'One option', 'options' => [['label' => 'only']]]];
    for ($i = 1; $i <= 8; $i++) {
        $questions[] = ['id' => "q{$i}", 'question' => "Q{$i}?", 'options' => [['label' => 'a'], ['label' => 'b']]];
    }
    $questions[] = ['id' => 'q1', 'question' => 'Duplicate', 'options' => [['label' => 'a'], ['label' => 'b']]];

    $result = ClaudeCodeOutputParser::parse(clarificationResult("```clarification\n" . json_encode(['questions' => $questions]) . "\n```"));

    expect(array_map(fn ($q) => $q->id, $result->clarificationQuestions))->toBe(['q1', 'q2', 'q3', 'q4', 'q5', 'q6']);
});

it('strips the clarification block from a summary', function () {
    $text = "Findings here.\n\n```clarification\n{\"questions\": []}\n```\n";

    expect(ClaudeCodeOutputParser::stripClarificationBlock($text))->toBe('Findings here.');
});
