<?php

use App\Ai\Agents\TaskDescriptionSummarizer;
use App\Services\TaskDescriptionSummary;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['yak.description_summarizer.enabled' => true]);
});

it('truncates the summary to 500 characters', function () {
    TaskDescriptionSummarizer::fake([str_repeat('a', 600)]);

    $summary = app(TaskDescriptionSummary::class)->generate('a long description');

    expect($summary)->not->toBeNull()
        ->and(mb_strlen($summary))->toBe(500);
});

it('returns null when the model responds with an empty string', function () {
    TaskDescriptionSummarizer::fake(['   ']);

    $summary = app(TaskDescriptionSummary::class)->generate('a description');

    expect($summary)->toBeNull();
});

it('returns null and logs a warning when the agent throws', function () {
    Log::shouldReceive('channel')
        ->with('yak')
        ->once()
        ->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->with('Description summary failed', Mockery::on(fn ($context) => isset($context['error'])));

    TaskDescriptionSummarizer::fake(function () {
        throw new RuntimeException('provider down');
    });

    $summary = app(TaskDescriptionSummary::class)->generate('a description');

    expect($summary)->toBeNull();
});

it('returns null without calling the agent when disabled', function () {
    config(['yak.description_summarizer.enabled' => false]);
    TaskDescriptionSummarizer::fake()->preventStrayPrompts();

    $summary = app(TaskDescriptionSummary::class)->generate('anything');

    expect($summary)->toBeNull();
});
