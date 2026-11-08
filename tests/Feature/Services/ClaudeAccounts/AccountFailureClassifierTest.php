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

/**
 * @return list<?ThreadBlockReason>
 */
function classifyCapturedStream(string $name): array
{
    $classifier = new AccountFailureClassifier;
    $classified = [];

    foreach (file(base_path("tests/Fixtures/threads/{$name}.jsonl"), FILE_IGNORE_NEW_LINES) as $row) {
        $line = json_decode(explode("\t", $row, 2)[1], true);

        if (! isset($line['_driver_sent'])) {
            $classified[] = $classifier->classify($line);
        }
    }

    return $classified;
}

it('classifies the captured usage limit shape', function () {
    $classified = classifyCapturedStream('usage-limit-5-hour');

    // The assistant line (error rate_limit, api_error usage_limit_reached) and the error result both block;
    // the rate_limit_event line and the lifecycle lines do not.
    expect(array_values(array_filter($classified)))->toBe([ThreadBlockReason::UsageLimit, ThreadBlockReason::UsageLimit]);
});

it('does not block on a 429 the CLI is still retrying', function () {
    $classified = classifyCapturedStream('rate-limit-429-retries');

    // Eight api_retry lines come first; only the final assistant and result lines, after the CLI gave up, block.
    expect(array_values(array_filter($classified)))->toBe([ThreadBlockReason::UsageLimit, ThreadBlockReason::UsageLimit])
        ->and(array_search(ThreadBlockReason::UsageLimit, $classified, true))->toBeGreaterThan(8);
});
