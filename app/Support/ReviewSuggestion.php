<?php

namespace App\Support;

/**
 * Turns a review finding's ```original / ```suggestion fence pair into a
 * GitHub suggestion anchored by content rather than by line numbers.
 *
 * The reviewer copies the exact lines it wants replaced into an
 * ```original fence. This class finds those lines in the file at the PR
 * head and derives `start_line` / `line` from where they actually are, so
 * the range always matches the lines the suggestion replaces. Anything it
 * cannot verify loses its fence and posts as prose: a missing
 * "Commit suggestion" button is recoverable, a corrupted file is not.
 */
final readonly class ReviewSuggestion
{
    public const OMITTED_NOTE = '_(Suggested change omitted: it could not be matched to the exact lines in this PR.)_';

    private const ORIGINAL_FENCE = '/^```original[^\n]*\n(.*?)^```[ \t]*$\n?/ms';

    private const SUGGESTION_FENCE = '/^```suggestion[^\n]*\n(.*?)^```[ \t]*$\n?/ms';

    private function __construct(
        public string $body,
        public int $line,
        public ?int $startLine,
        public bool $isSuggestion,
        public ?string $omittedReason,
    ) {}

    /**
     * @param  string|null  $fileContents  the file at the PR head, null when unreadable
     * @param  array<int, true>  $commentableLines  RIGHT-side lines inside a diff hunk
     * @param  string  $agentOutput  the reviewer's raw output, used to confirm the structurer copied the fence verbatim
     */
    public static function resolve(
        string $body,
        int $reportedLine,
        ?string $fileContents,
        array $commentableLines,
        string $agentOutput,
    ): self {
        $body = str_replace("\r\n", "\n", $body);

        $hasSuggestion = preg_match(self::SUGGESTION_FENCE, $body, $suggestionMatch) === 1;
        $hasOriginal = preg_match(self::ORIGINAL_FENCE, $body, $originalMatch) === 1;

        if (! $hasSuggestion) {
            return new self(self::withoutFences($body), $reportedLine, null, false, null);
        }

        $omit = fn (string $reason): self => new self(
            trim(self::withoutFences($body)) . "\n\n" . self::OMITTED_NOTE,
            $reportedLine,
            null,
            false,
            $reason,
        );

        if (! $hasOriginal) {
            return $omit('no_original_fence');
        }

        $original = self::fenceLines($originalMatch[1]);
        $replacement = $suggestionMatch[1];

        if ($original === []) {
            return $omit('empty_original');
        }

        if ($fileContents === null) {
            return $omit('file_unreadable');
        }

        if (! str_contains(str_replace("\r\n", "\n", $agentOutput), $replacement)) {
            return $omit('suggestion_not_verbatim');
        }

        if (self::normalize(self::fenceLines($replacement)) === self::normalize($original)) {
            return $omit('no_op');
        }

        $startLine = self::locate($original, self::fenceLines($fileContents), $reportedLine);

        if ($startLine === null) {
            return $omit('original_not_found');
        }

        $endLine = $startLine + count($original) - 1;

        for ($line = $startLine; $line <= $endLine; $line++) {
            if (! isset($commentableLines[$line])) {
                return $omit('outside_diff');
            }
        }

        return new self(
            trim((string) preg_replace(self::ORIGINAL_FENCE, '', $body)),
            $endLine,
            $startLine < $endLine ? $startLine : null,
            true,
            null,
        );
    }

    /**
     * Return the 1-based line where $needle starts in $haystack. A single
     * match wins wherever it is; with several, only one containing the
     * reported line is trusted.
     *
     * @param  array<int, string>  $needle
     * @param  array<int, string>  $haystack
     */
    private static function locate(array $needle, array $haystack, int $reportedLine): ?int
    {
        $needle = self::normalize($needle);
        $haystack = self::normalize($haystack);
        $length = count($needle);
        $matches = [];

        for ($i = 0; $i + $length <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $length) === $needle) {
                $matches[] = $i + 1;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        foreach ($matches as $start) {
            if ($reportedLine >= $start && $reportedLine < $start + $length) {
                return $start;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function fenceLines(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);

        if ($content === '') {
            return [];
        }

        return explode("\n", str_ends_with($content, "\n") ? substr($content, 0, -1) : $content);
    }

    /**
     * Trailing whitespace is invisible in review prose and often lost on
     * the way through the structurer; leading whitespace is significant.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private static function normalize(array $lines): array
    {
        return array_map(rtrim(...), $lines);
    }

    private static function withoutFences(string $body): string
    {
        return trim((string) preg_replace([self::ORIGINAL_FENCE, self::SUGGESTION_FENCE], '', $body));
    }
}
