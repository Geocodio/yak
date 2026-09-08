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
     * The external_id for the task covering every test that failed at one
     * commit. Keyed on the commit rather than the build: on GitHub Actions a
     * single push produces one workflow run per workflow file, so build ids
     * would split one breakage across several tasks.
     */
    public static function commitExternalId(string $repoSlug, string $commitSha): string
    {
        return 'flaky-test:commit:' . md5($repoSlug . ':' . $commitSha);
    }

    /**
     * The test class alone, with Pest's `> description` suffix and any
     * width-truncation ellipsis removed.
     *
     * Claims and pull-request file matching both key on this rather than the
     * full test name: Pest truncates names to the terminal width, so the same
     * test yields different names across builds, but the class survives.
     */
    public function testClass(): string
    {
        return self::normalizeTestClass($this->testName);
    }

    /**
     * Whether the class name itself was cut short by Pest's terminal-width
     * truncation. A truncated class cannot be resolved to a file path, so
     * pull-request matching falls back to the file basename.
     */
    public function testClassWasTruncated(): bool
    {
        $name = trim($this->testName);
        $classPart = str_contains($name, ' > ')
            ? substr($name, 0, (int) strpos($name, ' > '))
            : $name;

        return str_contains($classPart, "\u{2026}");
    }

    /**
     * The method half of `Class > description`, with any width-truncation
     * ellipsis removed. A name carrying no ` > ` has no method half and
     * returns an empty string.
     */
    public function testMethod(): string
    {
        $name = trim($this->testName);

        if (! str_contains($name, ' > ')) {
            return '';
        }

        $method = substr($name, (int) strpos($name, ' > ') + 3);
        $method = (string) preg_replace('/\x{2026}.*$/u', '', $method);

        return trim($method);
    }

    /**
     * Whether Pest cut this name short at the terminal width. A truncated
     * name that is a prefix of a longer one is that longer name, not a
     * separate test.
     */
    public function testNameWasTruncated(): bool
    {
        return str_contains($this->testName, "\u{2026}");
    }

    public static function normalizeTestClass(string $testName): string
    {
        $name = trim($testName);

        if (str_contains($name, ' > ')) {
            $name = substr($name, 0, (int) strpos($name, ' > '));
        }

        // Anything from the truncation ellipsis onwards is a partial word.
        $name = (string) preg_replace('/\x{2026}.*$/u', '', $name);

        return trim(rtrim(trim($name), '\\/ '));
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
