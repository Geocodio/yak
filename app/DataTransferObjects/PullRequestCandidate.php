<?php

namespace App\DataTransferObjects;

readonly class PullRequestCandidate
{
    /**
     * @param  list<string>  $changedFiles
     */
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public string $url,
        public array $changedFiles,
        public bool $isMerged,
    ) {}

    /**
     * @param  array<string, mixed>  $pr
     * @param  list<string>  $changedFiles
     */
    public static function fromApi(array $pr, array $changedFiles): self
    {
        return new self(
            number: (int) ($pr['number'] ?? 0),
            title: (string) ($pr['title'] ?? ''),
            body: (string) ($pr['body'] ?? ''),
            url: (string) ($pr['html_url'] ?? ''),
            changedFiles: $changedFiles,
            isMerged: ($pr['merged_at'] ?? null) !== null,
        );
    }
}
