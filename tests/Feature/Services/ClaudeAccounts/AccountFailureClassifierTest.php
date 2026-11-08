<?php

use App\Enums\ThreadBlockReason;
use App\Services\ClaudeAccounts\AccountFailureClassifier;

it('classifies authentication_failed as an invalid token', function () {
    $classifier = new AccountFailureClassifier;

    expect($classifier->classify(['type' => 'assistant', 'error' => 'authentication_failed', 'message' => ['content' => [['type' => 'text', 'text' => 'Invalid API key']]]]))->toBe(ThreadBlockReason::InvalidToken)
        ->and($classifier->classify(['type' => 'result', 'is_error' => true, 'api_error_status' => 401, 'result' => 'Invalid bearer token']))->toBe(ThreadBlockReason::InvalidToken);
});

it('classifies a 429 as a usage limit', function () {
    $classifier = new AccountFailureClassifier;

    expect($classifier->classify(['type' => 'assistant', 'error' => 'rate_limit', 'message' => ['content' => []]]))->toBe(ThreadBlockReason::UsageLimit)
        ->and($classifier->classify(['type' => 'result', 'is_error' => true, 'api_error_status' => 429, 'result' => 'Rate limited']))->toBe(ThreadBlockReason::UsageLimit)
        ->and($classifier->classify(['type' => 'result', 'is_error' => true, 'api_error_status' => null, 'result' => "You've hit your limit"]))->toBe(ThreadBlockReason::UsageLimit);
});

it('leaves ordinary lines alone', function () {
    $classifier = new AccountFailureClassifier;

    expect($classifier->classify(['type' => 'assistant', 'message' => ['content' => []]]))->toBeNull()
        ->and($classifier->classify(['type' => 'result', 'is_error' => false, 'api_error_status' => null, 'result' => 'done']))->toBeNull()
        ->and($classifier->classify(['type' => 'result', 'is_error' => true, 'api_error_status' => 500, 'result' => 'boom']))->toBeNull();
});
