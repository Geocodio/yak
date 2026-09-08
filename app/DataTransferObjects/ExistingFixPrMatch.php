<?php

namespace App\DataTransferObjects;

readonly class ExistingFixPrMatch
{
    public function __construct(
        public PullRequestCandidate $pullRequest,
        public string $reason,
    ) {}
}
