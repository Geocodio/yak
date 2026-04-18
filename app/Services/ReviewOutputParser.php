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

    /**
     * Extract the review's top-level JSON object from the agent output.
     *
     * Naive regex matching is unsafe here because `findings[].body` can
     * contain nested ```suggestion fences which would cause a non-greedy
     * match to stop early. Instead: find the ```json fence, then walk the
     * following characters tracking brace depth (string-aware) until the
     * top-level object closes. This is robust to any mix of inner fences,
     * escaped quotes, and trailing prose.
     */
    private function extractJsonBlock(string $output): ?string
    {
        $fencePos = strrpos($output, '```json');
        if ($fencePos === false) {
            return null;
        }

        $cursor = $fencePos + strlen('```json');
        $len = strlen($output);

        // Advance to the first `{` after the fence marker.
        while ($cursor < $len && $output[$cursor] !== '{') {
            $cursor++;
        }

        if ($cursor >= $len) {
            return null;
        }

        $start = $cursor;
        $depth = 0;
        $inString = false;
        $escaped = false;

        for (; $cursor < $len; $cursor++) {
            $char = $output[$cursor];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($output, $start, $cursor - $start + 1);
                }
            }
        }

        return null;
    }
}
