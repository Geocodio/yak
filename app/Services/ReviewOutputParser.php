<?php

namespace App\Services;

use App\Ai\Agents\ReviewStructurer;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use Laravel\Ai\Responses\StructuredAgentResponse;

class ReviewOutputParser
{
    private const REQUIRED_KEYS = ['summary', 'verdict', 'verdict_detail', 'findings'];

    private const MAX_STRUCTURER_ATTEMPTS = 2;

    public function __construct(private readonly ReviewStructurer $structurer = new ReviewStructurer) {}

    public function parse(string $agentOutput): ParsedReview
    {
        $trimmed = trim($agentOutput);
        if ($trimmed === '') {
            throw new \RuntimeException('Agent produced no review output to structure.');
        }

        $decoded = $this->structure($trimmed);

        if (! is_array($decoded['findings'])) {
            throw new \RuntimeException('`findings` must be an array.');
        }

        $findings = array_map(
            fn (array $raw): ReviewFinding => ReviewFinding::fromArray($raw),
            $decoded['findings'],
        );

        $rawPrior = $decoded['prior_findings'] ?? [];
        if (! is_array($rawPrior)) {
            throw new \RuntimeException('`prior_findings` must be an array.');
        }

        $priorFindings = array_map(
            fn (array $raw): ParsedPriorFinding => ParsedPriorFinding::fromArray($raw),
            $rawPrior,
        );

        return new ParsedReview(
            summary: (string) $decoded['summary'],
            verdict: (string) $decoded['verdict'],
            verdictDetail: (string) $decoded['verdict_detail'],
            findings: $findings,
            priorFindings: $priorFindings,
            risk: in_array($decoded['risk'] ?? null, ['low', 'high', 'unknown'], true)
                ? $decoded['risk'] : 'unknown',
            signals: is_array($decoded['signals'] ?? null) ? $decoded['signals'] : [],
        );
    }

    /**
     * Run the structurer, asking again when a required key is missing. On the
     * final attempt a missing verdict is inferred from the finding severities,
     * the same rule the structurer is told to apply.
     *
     * @return array<string, mixed>
     */
    private function structure(string $agentOutput): array
    {
        for ($attempt = 1; ; $attempt++) {
            /** @var StructuredAgentResponse $response */
            $response = $this->structurer->prompt($agentOutput);

            /** @var array<string, mixed> $decoded */
            $decoded = $response->structured;

            $isFinalAttempt = $attempt >= self::MAX_STRUCTURER_ATTEMPTS;

            if ($isFinalAttempt && ! array_key_exists('verdict', $decoded) && is_array($decoded['findings'] ?? null)) {
                $decoded['verdict'] = $this->inferVerdict($decoded['findings']);
            }

            $missingKeys = array_values(array_diff(self::REQUIRED_KEYS, array_keys($decoded)));

            if ($missingKeys === []) {
                return $decoded;
            }

            if ($isFinalAttempt) {
                throw new \RuntimeException("Structured review missing required key: {$missingKeys[0]}");
            }
        }
    }

    /**
     * @param  array<int, mixed>  $findings
     */
    private function inferVerdict(array $findings): string
    {
        $severities = array_column(array_filter($findings, 'is_array'), 'severity');

        return match (true) {
            in_array('must_fix', $severities, true) => 'Request changes',
            in_array('should_fix', $severities, true) => 'Approve with suggestions',
            default => 'Approve',
        };
    }
}
