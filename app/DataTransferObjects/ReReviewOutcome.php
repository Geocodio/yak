<?php

namespace App\DataTransferObjects;

use App\Models\YakTask;

/**
 * What asking for a review did: the task to show, whether Yak started a new
 * review, and the sentence to flash (an error when `isError`).
 */
final readonly class ReReviewOutcome
{
    public function __construct(
        public YakTask $task,
        public bool $isNewReview,
        public bool $isError,
        public string $sentence,
    ) {}
}
