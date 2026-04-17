<?php

use App\Ai\Agents\TaskIntentClassifier;
use App\Enums\TaskMode;
use App\Services\TaskIntentClassification;

beforeEach(function () {
    config(['yak.intent_classifier.enabled' => true]);
});

it('returns Research when the agent responds "research"', function () {
    TaskIntentClassifier::fake(['research']);

    $mode = app(TaskIntentClassification::class)->classify('What does this middleware do?');

    expect($mode)->toBe(TaskMode::Research);
});

it('returns Fix when the agent responds "fix"', function () {
    TaskIntentClassifier::fake(['fix']);

    $mode = app(TaskIntentClassification::class)->classify('Fix the broken export job');

    expect($mode)->toBe(TaskMode::Fix);
});

it('normalises whitespace and case', function () {
    TaskIntentClassifier::fake(["  RESEARCH\n"]);

    $mode = app(TaskIntentClassification::class)->classify('explain caching');

    expect($mode)->toBe(TaskMode::Research);
});

it('falls back to Fix on malformed output', function () {
    TaskIntentClassifier::fake(['I think it is a fix.']);

    $mode = app(TaskIntentClassification::class)->classify('ambiguous request');

    expect($mode)->toBe(TaskMode::Fix);
});

it('falls back to Fix on agent exception', function () {
    TaskIntentClassifier::fake(function () {
        throw new RuntimeException('provider down');
    });

    $mode = app(TaskIntentClassification::class)->classify('whatever');

    expect($mode)->toBe(TaskMode::Fix);
});

it('returns Fix without calling the agent when disabled', function () {
    config(['yak.intent_classifier.enabled' => false]);
    TaskIntentClassifier::fake()->preventStrayPrompts();

    $mode = app(TaskIntentClassification::class)->classify('anything');

    expect($mode)->toBe(TaskMode::Fix);
});
