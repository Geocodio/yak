<?php

use App\YakPromptBuilder;

test('followUpPrompt embeds the instructions and frames the PR as already open', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Handle the empty-state when there are no rows');

    expect($prompt)->toContain('Handle the empty-state when there are no rows')
        ->and($prompt)->toContain('already open')
        ->and($prompt)->toContain('same branch');
});

test('followUpPrompt tells the agent to summarize only the follow-up changes', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Handle the empty-state when there are no rows');

    expect($prompt)->toContain('only what you changed in response to this feedback')
        ->and($prompt)->toContain('Do not restate the original PR description');
});

test('followUpPrompt asks for the two-section summary', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Handle the empty-state');

    expect($prompt)->toContain('## What changed in this run')
        ->and($prompt)->toContain('## PR description')
        ->and($prompt)->toContain('`Unchanged.`')
        ->and($prompt)->toContain('Rewrite; do not append a changelog');
});

test('followUpPrompt asks for a recapture only when something visible changed', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Handle the empty-state');

    expect($prompt)->toContain('capture them again under rule 6')
        ->and($prompt)->toContain('If nothing visible changed, capture nothing');
});

test('followUpPrompt tells the agent to answer questions without changing code', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Why a queue here?');

    expect($prompt)->toContain('question or a disagreement')
        ->and($prompt)->toContain('do not change code for them');
});
