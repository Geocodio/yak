<?php

use App\Ai\Agents\ReviewStructurer;
use App\Services\ReviewOutputParser;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * @param  array<string, mixed>  ...$structuredResponses  returned in order, the last one repeating
 */
function fakeStructurer(array ...$structuredResponses): ReviewStructurer
{
    $mock = Mockery::mock(ReviewStructurer::class);
    $mock->shouldReceive('prompt')->andReturn(...array_map(
        fn (array $structured): StructuredAgentResponse => new StructuredAgentResponse(
            invocationId: 'inv-test',
            structured: $structured,
            text: '',
            usage: new Usage(0, 0, 0, 0),
            meta: new Meta(model: 'claude-haiku-4-5-20251001', provider: 'anthropic'),
        ),
        $structuredResponses,
    ));

    return $mock;
}

it('builds ParsedReview from the structurer output', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'Adds retry with backoff.',
        'verdict' => 'Approve with suggestions',
        'verdict_detail' => 'One blocker found.',
        'findings' => [[
            'file' => 'app/Foo.php',
            'line' => 12,
            'severity' => 'must_fix',
            'category' => 'Performance',
            'body' => 'Null check missing.',
        ]],
    ]));

    $parsed = $parser->parse('## Summary\nAdds retry with backoff.\n...');

    expect($parsed->summary)->toBe('Adds retry with backoff.')
        ->and($parsed->verdict)->toBe('Approve with suggestions')
        ->and($parsed->findings)->toHaveCount(1)
        ->and($parsed->findings[0]->severity)->toBe('must_fix')
        ->and($parsed->findings[0]->body)->toBe('Null check missing.');
});

it('casts finding fields to their types', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'x',
        'verdict' => 'Approve',
        'verdict_detail' => 'y',
        'findings' => [[
            'file' => 'a.php',
            'line' => 1,
            'severity' => 'consider',
            'category' => 'Simplicity',
            'body' => 'b',
        ]],
    ]));

    $parsed = $parser->parse('text');

    expect($parsed->findings[0]->line)->toBe(1)
        ->and($parsed->findings[0]->body)->toBe('b');
});

it('accepts an empty findings list for a clean PR', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'Small doc fix.',
        'verdict' => 'Approve',
        'verdict_detail' => 'Nothing to flag.',
        'findings' => [],
    ]));

    $parsed = $parser->parse('text');

    expect($parsed->findings)->toBeEmpty()
        ->and($parsed->verdict)->toBe('Approve');
});

it('throws when the agent output is empty', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'unused', 'verdict' => 'Approve', 'verdict_detail' => '', 'findings' => [],
    ]));

    $parser->parse('   ');
})->throws(RuntimeException::class, 'no review output');

it('never infers low risk from a clean verdict', function (?string $risk, string $expected) {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'Small fix.', 'verdict' => 'Approve', 'verdict_detail' => 'Clean.',
        'findings' => [], 'risk' => $risk,
    ]));
    expect($parser->parse('Review prose')->risk)->toBe($expected);
})->with([[null, 'unknown'], ['invalid', 'unknown'], ['low', 'low'], ['high', 'high']]);

it('throws when the structurer returns missing keys', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'x',
        'verdict' => 'Approve',
        // verdict_detail + findings missing
    ]));

    $parser->parse('text');
})->throws(RuntimeException::class, 'missing required key');

it('asks the structurer again when a required key is missing', function () {
    $structurer = fakeStructurer(
        ['summary' => 's', 'verdict_detail' => 'd', 'findings' => []],
        ['summary' => 's', 'verdict' => 'Request changes', 'verdict_detail' => 'd', 'findings' => []],
    );

    $parsed = (new ReviewOutputParser($structurer))->parse('text');

    expect($parsed->verdict)->toBe('Request changes');
    $structurer->shouldHaveReceived('prompt')->twice();
});

it('infers a missing verdict from finding severities after the retry', function (array $severities, string $expected) {
    $findings = array_map(fn (string $severity): array => [
        'file' => 'a.php', 'line' => 1, 'severity' => $severity, 'category' => 'Correctness', 'body' => 'b',
    ], $severities);

    $parser = new ReviewOutputParser(fakeStructurer(
        ['summary' => 's', 'verdict_detail' => 'd', 'findings' => $findings],
    ));

    expect($parser->parse('text')->verdict)->toBe($expected);
})->with([
    'must fix present' => [['consider', 'must_fix'], 'Request changes'],
    'should fix present' => [['should_fix', 'consider'], 'Approve with suggestions'],
    'only consider' => [['consider'], 'Approve'],
    'no findings' => [[], 'Approve'],
]);

it('gives up after the retry when other required keys stay missing', function () {
    $structurer = fakeStructurer(['verdict' => 'Approve', 'verdict_detail' => 'd', 'findings' => []]);

    expect(fn () => (new ReviewOutputParser($structurer))->parse('text'))
        ->toThrow(RuntimeException::class, 'missing required key: summary');
    $structurer->shouldHaveReceived('prompt')->twice();
});

it('parses prior_findings into ParsedPriorFinding DTOs', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 's', 'verdict' => 'Approve', 'verdict_detail' => 'd',
        'findings' => [],
        'prior_findings' => [
            ['id' => 11, 'status' => 'fixed', 'reply_body' => 'Fixed in deadbee.'],
            ['id' => 12, 'status' => 'untouched'],
            ['id' => 13, 'status' => 'still_outstanding', 'reply_body' => 'Still busted on line 89.'],
        ],
    ]));

    $parsed = $parser->parse('text');

    expect($parsed->priorFindings)->toHaveCount(3)
        ->and($parsed->priorFindings[0]->commentId)->toBe(11)
        ->and($parsed->priorFindings[0]->status)->toBe('fixed')
        ->and($parsed->priorFindings[0]->replyBody)->toBe('Fixed in deadbee.')
        ->and($parsed->priorFindings[1]->status)->toBe('untouched')
        ->and($parsed->priorFindings[1]->replyBody)->toBe('');
});

it('defaults priorFindings to empty when missing from structured output', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 's', 'verdict' => 'Approve', 'verdict_detail' => 'd',
        'findings' => [],
    ]));

    $parsed = $parser->parse('text');

    expect($parsed->priorFindings)->toBe([]);
});

it('preserves structured risk signals without manufacturing missing evidence', function () {
    $signals = ['model_confidence' => 91, 'uncertainties' => ['Caller context unavailable']];
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 's', 'verdict' => 'Approve', 'verdict_detail' => 'd',
        'findings' => [], 'signals' => $signals,
    ]));

    expect($parser->parse('text')->signals)->toBe($signals);
});
