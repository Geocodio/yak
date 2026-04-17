<?php

namespace App\Services;

use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;

class ReviewOutputParser
{
    public function parse(string $agentOutput): ParsedReview
    {
        $json = $this->extractJsonBlock($agentOutput);

        if ($json === null) {
            throw new \RuntimeException('Agent did not emit a JSON code block.');
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Agent JSON block did not decode to an object.');
        }

        foreach (['summary', 'verdict', 'verdict_detail', 'findings'] as $required) {
            if (! array_key_exists($required, $decoded)) {
                throw new \RuntimeException("Review output missing required key: {$required}");
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

    private function extractJsonBlock(string $output): ?string
    {
        if (preg_match_all('/```json\s*(.*?)```/s', $output, $matches) === 0) {
            return null;
        }

        $blocks = $matches[1];

        return trim(end($blocks)) ?: null;
    }
}
