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

        return new self(
            file: (string) $raw['file'],
            line: (int) $raw['line'],
            severity: (string) $raw['severity'],
            category: (string) $raw['category'],
            body: (string) $raw['body'],
        );
    }
}
