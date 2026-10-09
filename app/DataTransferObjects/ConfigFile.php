<?php

namespace App\DataTransferObjects;

/** The stored state of one `.yak/` file: its last valid version and its current error. */
final readonly class ConfigFile
{
    /** @param  array{number: int, title: string, url: string}|null  $errorPullRequest */
    public function __construct(
        public string $name,
        public mixed $data,
        public ?string $content,
        public ?string $validCommitSha,
        public ?string $error,
        public ?string $errorCommitSha,
        public ?array $errorPullRequest,
    ) {}

    public function isValid(): bool
    {
        return $this->error === null;
    }
}
