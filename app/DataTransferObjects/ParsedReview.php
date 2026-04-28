<?php

namespace App\DataTransferObjects;

final readonly class ParsedReview
{
    /**
     * @param  array<int, ReviewFinding>  $findings
     * @param  array<int, ParsedPriorFinding>  $priorFindings
     */
    public function __construct(
        public string $summary,
        public string $verdict,
        public string $verdictDetail,
        public array $findings,
        public array $priorFindings = [],
    ) {}
}
