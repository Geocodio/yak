<?php

use App\YakPromptBuilder;

test('followUpPrompt embeds the instructions and frames the PR as already open', function () {
    $prompt = YakPromptBuilder::followUpPrompt('Handle the empty-state when there are no rows');

    expect($prompt)->toContain('Handle the empty-state when there are no rows')
        ->and($prompt)->toContain('already open')
        ->and($prompt)->toContain('same branch');
});
