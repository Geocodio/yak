<?php

use App\Ai\Agents\ReviewFeedbackTriage;
use App\Services\ReviewFeedbackTriageDecision;
use Laravel\Ai\Prompts\AgentPrompt;

it('returns none when the agent answers none', function () {
    ReviewFeedbackTriage::fake(['none']);

    $decision = app(ReviewFeedbackTriageDecision::class)->decide('Add CSV export', "@bob submitted a review (approved):\n\n> LGTM");

    expect($decision)->toBe('none');
});

it('returns act when the agent answers act', function () {
    ReviewFeedbackTriage::fake(['act']);

    $decision = app(ReviewFeedbackTriageDecision::class)->decide('Add CSV export', '- app/Foo.php:7 — off by one');

    expect($decision)->toBe('act');
});

it('normalises whitespace and case', function () {
    ReviewFeedbackTriage::fake(["  NONE\n"]);

    expect(app(ReviewFeedbackTriageDecision::class)->decide('x', 'y'))->toBe('none');
});

it('resolves anything unparsable to act', function () {
    ReviewFeedbackTriage::fake(['I think nothing needs doing here.']);

    expect(app(ReviewFeedbackTriageDecision::class)->decide('x', 'y'))->toBe('act');
});

it('resolves a thrown error to act', function () {
    ReviewFeedbackTriage::fake(function () {
        throw new RuntimeException('provider down');
    });

    expect(app(ReviewFeedbackTriageDecision::class)->decide('x', 'y'))->toBe('act');
});

it('sends the task description and the feedback to the agent', function () {
    ReviewFeedbackTriage::fake(['none']);

    app(ReviewFeedbackTriageDecision::class)->decide('Add CSV export', '> LGTM');

    ReviewFeedbackTriage::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Add CSV export') && $prompt->contains('> LGTM'));
});
