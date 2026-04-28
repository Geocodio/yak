<?php

use App\Ai\Agents\ReviewStructurer;
use App\Services\ReviewOutputParser;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function fakeStructurer(array $structured): ReviewStructurer
{
    $mock = Mockery::mock(ReviewStructurer::class);
    $mock->shouldReceive('prompt')->andReturn(
        new StructuredAgentResponse(
            invocationId: 'inv-test',
            structured: $structured,
            text: '',
            usage: new Usage(0, 0, 0, 0),
            meta: new Meta(model: 'claude-haiku-4-5-20251001', provider: 'anthropic'),
        ),
    );

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
            'suggestion_loc' => 2,
        ]],
    ]));

    $parsed = $parser->parse('## Summary\nAdds retry with backoff.\n...');

    expect($parsed->summary)->toBe('Adds retry with backoff.')
        ->and($parsed->verdict)->toBe('Approve with suggestions')
        ->and($parsed->findings)->toHaveCount(1)
        ->and($parsed->findings[0]->severity)->toBe('must_fix')
        ->and($parsed->findings[0]->suggestionLoc)->toBe(2);
});

it('accepts a finding without suggestion_loc', function () {
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

    expect($parsed->findings[0]->suggestionLoc)->toBeNull();
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

it('throws when the structurer returns missing keys', function () {
    $parser = new ReviewOutputParser(fakeStructurer([
        'summary' => 'x',
        'verdict' => 'Approve',
        // verdict_detail + findings missing
    ]));

    $parser->parse('text');
})->throws(RuntimeException::class, 'missing required key');

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
