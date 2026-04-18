<?php

namespace App\Services;

use App\Ai\Agents\ReviewStructurer;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Turn a free-form PR review (prose text from the sandboxed agent) into
 * our ParsedReview DTO.
 *
 * The translation uses Laravel AI SDK's structured output so we never
 * hand-parse JSON from the agent's output — the schema on ReviewStructurer
 * guarantees the shape we get back.
 */
class ReviewOutputParser
{
    public function __construct(private readonly ReviewStructurer $structurer = new ReviewStructurer) {}

    public function parse(string $agentOutput): ParsedReview
    {
        $trimmed = trim($agentOutput);
        if ($trimmed === '') {
            throw new \RuntimeException('Agent produced no review output to structure.');
        }

        /** @var StructuredAgentResponse $response */
        $response = $this->structurer->prompt($trimmed);

        /** @var array<string, mixed> $decoded */
        $decoded = $response->structured;

        foreach (['summary', 'verdict', 'verdict_detail', 'findings'] as $required) {
            if (! array_key_exists($required, $decoded)) {
                throw new \RuntimeException("Structured review missing required key: {$required}");
            }
        }

        if (! is_array($decoded['findings'])) {
            throw new \RuntimeException('`findings` must be an array.');
        }

        $findings = array_map(
            fn (array $raw): ReviewFinding => ReviewFinding::fromArray($raw),
            $decoded['findings'],
        );

        return new ParsedReview(
            summary: (string) $decoded['summary'],
            verdict: (string) $decoded['verdict'],
            verdictDetail: (string) $decoded['verdict_detail'],
            findings: $findings,
        );
    }
}
