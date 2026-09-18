<?php

namespace App\DataTransferObjects;

/**
 * A follow-up run's final summary, split into the part that becomes the
 * PR comment and the optional rewritten PR description.
 */
final readonly class ParsedFollowUpSummary
{
    /**
     * @param  array<int, string>  $replies  review-comment id => reply body, in the order the agent wrote them
     */
    public function __construct(
        public string $changes,
        public ?string $description,
        public array $replies = [],
    ) {}
}
