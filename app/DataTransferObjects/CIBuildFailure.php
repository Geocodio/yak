<?php

namespace App\DataTransferObjects;

readonly class CIBuildFailure
{
    public function __construct(
        public string $testName,
        public string $output,
        public string $buildUrl,
        public string $buildId,
        public ?string $branch = null,
        public ?string $commitSha = null,
    ) {}

    /**
     * Generate a deterministic external_id for deduplication.
     *
     * Uses the normalized test name so Pest's trailing-`…` truncation
     * (which varies by terminal width) doesn't produce different
     * external_ids for the same test across builds.
     */
    public function externalId(): string
    {
        return 'flaky-test:' . md5(self::normalizeTestName($this->testName));
    }

    /**
     * Strip Pest's trailing ellipsis and surrounding whitespace so the
     * test name is stable across builds regardless of terminal width.
     */
    public static function normalizeTestName(string $testName): string
    {
        return trim(rtrim(trim($testName), '…'));
    }
}
