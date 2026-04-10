<?php

use App\ClaudeOutputParser;

/*
|--------------------------------------------------------------------------
| Valid JSON Parsing
|--------------------------------------------------------------------------
*/

test('parses valid JSON result with all fields', function () {
    $json = json_encode([
        'result' => 'Fixed the authentication bug',
        'cost_usd' => 1.25,
        'session_id' => 'sess_abc123',
        'num_turns' => 12,
        'duration_ms' => 45000,
        'is_error' => false,
    ]);

    $parser = new ClaudeOutputParser($json);

    expect($parser->isValid())->toBeTrue()
        ->and($parser->isError())->toBeFalse()
        ->and($parser->isClarification())->toBeFalse()
        ->and($parser->resultSummary())->toBe('Fixed the authentication bug')
        ->and($parser->costUsd())->toBe(1.25)
        ->and($parser->sessionId())->toBe('sess_abc123')
        ->and($parser->numTurns())->toBe(12)
        ->and($parser->durationMs())->toBe(45000);
});

test('parses result_summary field as alternative to result', function () {
    $json = json_encode([
        'result_summary' => 'Alternative summary field',
        'session_id' => 'sess_xyz',
    ]);

    $parser = new ClaudeOutputParser($json);

    expect($parser->resultSummary())->toBe('Alternative summary field');
});

/*
|--------------------------------------------------------------------------
| Clarification Detection
|--------------------------------------------------------------------------
*/

test('detects clarification response', function () {
    $json = json_encode([
        'clarification_needed' => true,
        'options' => ['Option A', 'Option B', 'Option C'],
        'session_id' => 'sess_clarify123',
        'cost_usd' => 0.50,
        'num_turns' => 3,
    ]);

    $parser = new ClaudeOutputParser($json);

    expect($parser->isClarification())->toBeTrue()
        ->and($parser->clarificationOptions())->toBe(['Option A', 'Option B', 'Option C'])
        ->and($parser->sessionId())->toBe('sess_clarify123');
});

test('clarification options returns empty array when not a clarification', function () {
    $json = json_encode([
        'result' => 'Done',
        'session_id' => 'sess_ok',
    ]);

    $parser = new ClaudeOutputParser($json);

    expect($parser->isClarification())->toBeFalse()
        ->and($parser->clarificationOptions())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Error State Detection
|--------------------------------------------------------------------------
*/

test('detects error state from is_error flag', function () {
    $json = json_encode([
        'is_error' => true,
        'result' => 'Something went wrong',
        'session_id' => 'sess_err',
    ]);

    $parser = new ClaudeOutputParser($json);

    expect($parser->isError())->toBeTrue()
        ->and($parser->resultSummary())->toBe('Something went wrong');
});

test('invalid JSON is treated as error', function () {
    $parser = new ClaudeOutputParser('not valid json {{{');

    expect($parser->isValid())->toBeFalse()
        ->and($parser->isError())->toBeTrue();
});

test('empty string is treated as error', function () {
    $parser = new ClaudeOutputParser('');

    expect($parser->isValid())->toBeFalse()
        ->and($parser->isError())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Malformed JSON Handling
|--------------------------------------------------------------------------
*/

test('malformed JSON does not crash and returns defaults', function () {
    $parser = new ClaudeOutputParser('{broken json');

    expect($parser->isValid())->toBeFalse()
        ->and($parser->resultSummary())->toBe('')
        ->and($parser->costUsd())->toBe(0.0)
        ->and($parser->sessionId())->toBe('')
        ->and($parser->numTurns())->toBe(0)
        ->and($parser->durationMs())->toBe(0)
        ->and($parser->isClarification())->toBeFalse()
        ->and($parser->clarificationOptions())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Missing Fields Use Defaults
|--------------------------------------------------------------------------
*/

test('missing fields return sensible defaults', function () {
    $json = json_encode(['session_id' => 'sess_minimal']);

    $parser = new ClaudeOutputParser($json);

    expect($parser->isValid())->toBeTrue()
        ->and($parser->isError())->toBeFalse()
        ->and($parser->resultSummary())->toBe('')
        ->and($parser->costUsd())->toBe(0.0)
        ->and($parser->numTurns())->toBe(0)
        ->and($parser->durationMs())->toBe(0);
});

test('empty JSON object returns defaults', function () {
    $parser = new ClaudeOutputParser('{}');

    expect($parser->isValid())->toBeTrue()
        ->and($parser->isError())->toBeFalse()
        ->and($parser->sessionId())->toBe('')
        ->and($parser->resultSummary())->toBe('')
        ->and($parser->costUsd())->toBe(0.0)
        ->and($parser->numTurns())->toBe(0);
});

test('toArray returns parsed data', function () {
    $data = ['result' => 'test', 'session_id' => 'sess_1'];
    $parser = new ClaudeOutputParser(json_encode($data));

    expect($parser->toArray())->toBe($data);
});

test('toArray returns empty array for invalid JSON', function () {
    $parser = new ClaudeOutputParser('invalid');

    expect($parser->toArray())->toBe([]);
});
