<?php

namespace App\DataTransferObjects;

readonly class BuildResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $passed,
        public string $externalId,
        public string $repository,
        public ?string $output = null,
        public ?string $commitSha = null,
        public array $metadata = [],
    ) {}
}
