<?php

namespace App\DataTransferObjects;

readonly class CIBuildFailure
{
    public function __construct(
        public string $testName,
        public string $output,
        public string $buildUrl,
        public string $buildId,
    ) {}

    /**
     * Generate a deterministic external_id for deduplication.
     */
    public function externalId(): string
    {
        return 'flaky-test:'.md5($this->testName);
    }
}
