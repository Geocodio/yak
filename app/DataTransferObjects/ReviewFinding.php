<?php

namespace App\DataTransferObjects;

final readonly class ReviewFinding
{
    public function __construct(
        public string $file,
        public int $line,
        public string $severity,
        public string $category,
        public string $body,
        public ?int $suggestionLoc = null,
        public ?int $startLine = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (['file', 'line', 'severity', 'category', 'body'] as $key) {
            if (! array_key_exists($key, $raw)) {
                throw new \RuntimeException("Finding missing required key: {$key}");
            }
        }

        $line = (int) $raw['line'];
        $startLine = isset($raw['start_line']) ? (int) $raw['start_line'] : null;

        // Drop start_line if it doesn't describe a real range (must be
        // strictly less than the anchor line; GitHub rejects equal/inverted
        // ranges with a 422).
        if ($startLine !== null && $startLine >= $line) {
            $startLine = null;
        }

        return new self(
            file: (string) $raw['file'],
            line: $line,
            severity: (string) $raw['severity'],
            category: (string) $raw['category'],
            body: (string) $raw['body'],
            suggestionLoc: isset($raw['suggestion_loc']) ? (int) $raw['suggestion_loc'] : null,
            startLine: $startLine,
        );
    }
}
