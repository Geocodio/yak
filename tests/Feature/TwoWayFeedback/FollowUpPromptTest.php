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

test('followUpPrompt tells the agent to answer questions without changing code', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Why a queue here?');

    expect($prompt)->toContain('question or a disagreement')
        ->and($prompt)->toContain('do not change code for them');
});
