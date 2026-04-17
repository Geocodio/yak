<?php

namespace App\DataTransferObjects;

final readonly class ParsedReview
{
    /**
     * @param  array<int, ReviewFinding>  $findings
     */
    public function __construct(
        public string $summary,
        public string $verdict,
        public string $verdictDetail,
        public array $findings,
    ) {}
}
