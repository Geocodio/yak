<?php

namespace App\Services;

/**
 * Parses Pest failure blocks out of a raw CI build log.
 *
 * Shared by every CI build scanner. Drone hands us step logs; GitHub Actions
 * hands us job logs with an RFC3339 timestamp glued to the front of every
 * line. Both interleave ANSI colour codes. Normalizing both quirks here keeps
 * the scanners thin and means a parser fix lands for every CI system at once.
 */
class TestFailureParser
{
    /**
     * Pest's failure header. The separator is two-or-more spaces:
     *
     *   FAILED  Tests\Foo\BarTest > it does something
     *   FAILED  Tests\Foo\BarTest…   RateLimitException
     *
     * Pest truncates the test name to the terminal width, so the ` > it does
     * something` half is frequently missing and an exception class sits in
     * its place. Both shapes must parse.
     */
    private const FAILED_HEADER = '/^\s*FAILED\s{2,}(\S.*?)\s*$/';

    /** Pest's run summary — ends the trailing failure block. */
    private const SUMMARY_LINE = '/^\s*Tests:\s+\d+\s+failed/';

    /**
     * A captured name only counts as a test identifier if it looks like one:
     * a namespaced class, a file path, or Pest's `class > description` form.
     * Without this, bare `FAILED` banners from build summaries and parallel
     * runner stats would produce blank test names.
     */
    private const LOOKS_LIKE_TEST = '/[\\\\\/]|\s>\s/';

    /**
     * @return array<int, array{test: string, output: string}>
     */
    public function parse(string $log): array
    {
        $lines = explode("\n", $this->normalize($log));

        $failures = [];

        /** @var string|null $currentTest */
        $currentTest = null;
        $currentOutput = '';

        $flush = function () use (&$failures, &$currentTest, &$currentOutput): void {
            if ($currentTest !== null) {
                $failures[] = [
                    'test' => $currentTest,
                    'output' => trim($currentOutput),
                ];
            }
            $currentTest = null;
            $currentOutput = '';
        };

        foreach ($lines as $line) {
            $testName = $this->matchFailureHeader($line);

            if ($testName !== null) {
                $flush();
                $currentTest = $testName;
                $currentOutput = $line . "\n";

                continue;
            }

            if (preg_match(self::SUMMARY_LINE, $line) === 1) {
                $flush();

                continue;
            }

            if ($currentTest !== null) {
                $currentOutput .= $line . "\n";
            }
        }

        $flush();

        return $failures;
    }

    /**
     * Strip the noise both CI systems wrap around the useful output: ANSI
     * colour codes, GitHub Actions' per-line timestamp prefix, and CRs.
     */
    private function normalize(string $log): string
    {
        $log = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $log) ?? $log;

        // GitHub Actions prefixes every log line with e.g.
        // "2026-08-27T07:24:34.4244357Z ". Left in place it defeats every
        // line-anchored pattern below.
        $log = preg_replace('/^\d{4}-\d{2}-\d{2}T[\d:.]+Z /m', '', $log) ?? $log;

        return str_replace("\r", '', $log);
    }

    /**
     * Returns the test name from a Pest `FAILED` header, or null if the line
     * isn't one.
     */
    private function matchFailureHeader(string $line): ?string
    {
        if (preg_match(self::FAILED_HEADER, $line, $matches) !== 1) {
            return null;
        }

        // Split the trailing exception class off the test name. Pest pads the
        // gap between them to at least two spaces; the test name itself is
        // single-spaced.
        $parts = preg_split('/\s{2,}/', $matches[1]);
        $testName = $parts === false ? trim($matches[1]) : trim($parts[0]);

        if ($testName === '' || preg_match(self::LOOKS_LIKE_TEST, $testName) !== 1) {
            return null;
        }

        return $testName;
    }
}
