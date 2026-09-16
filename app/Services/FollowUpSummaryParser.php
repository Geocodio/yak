<?php

namespace App\Services;

use App\DataTransferObjects\ParsedFollowUpSummary;

/**
 * Splits a follow-up run's summary on the two headings the follow-up prompt
 * asks for. Anything before the "PR description" heading is the change
 * summary, and a "## Replies" section within it is pulled out into tagged
 * reply entries. Missing headings degrade to today's behaviour: the whole
 * output is the change summary and the description is left alone.
 */
class FollowUpSummaryParser
{
    private const string CHANGES_HEADING = 'what changed in this run';

    private const string DESCRIPTION_HEADING = 'pr description';

    private const string REPLIES_HEADING = 'replies';

    public function parse(string $agentOutput): ParsedFollowUpSummary
    {
        $descriptionOffset = $this->headingOffset($agentOutput, self::DESCRIPTION_HEADING);

        $changesText = $descriptionOffset === null
            ? $agentOutput
            : substr($agentOutput, 0, $descriptionOffset['start']);

        [$changes, $replies] = $this->extractReplies($this->stripStrayMarkers($this->stripChangesHeading($changesText)));

        if ($descriptionOffset === null) {
            return new ParsedFollowUpSummary(trim($changes), null, $replies);
        }

        $description = trim($this->stripStrayMarkers(substr($agentOutput, $descriptionOffset['end'])));

        if ($description === '' || $this->isUnchanged($description)) {
            $description = null;
        }

        return new ParsedFollowUpSummary(trim($changes), $description, $replies);
    }

    /**
     * Pull `- [c:<id>] ...` entries out of a "## Replies" section. An entry
     * runs until the next tagged entry or the end of the section, so a
     * reply may span several lines. Untagged prose in the section stays in
     * the changes text; the heading itself is dropped.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function extractReplies(string $changes): array
    {
        $offset = $this->headingOffset($changes, self::REPLIES_HEADING);

        if ($offset === null) {
            return [$changes, []];
        }

        $before = rtrim(substr($changes, 0, $offset['start']));
        $section = substr($changes, $offset['end']);

        $replies = [];
        $leftover = [];
        $currentId = null;
        $currentLines = [];

        $flush = function () use (&$replies, &$currentId, &$currentLines): void {
            if ($currentId !== null) {
                $replies[$currentId] = trim(implode("\n", array_map(trim(...), $currentLines)));
            }

            $currentId = null;
            $currentLines = [];
        };

        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            if (preg_match('/^\s*-\s*\[c:(\d+)\]\s*(.*)$/', $line, $match) === 1) {
                $flush();
                $currentId = (int) $match[1];
                $currentLines = [$match[2]];

                continue;
            }

            if ($currentId !== null) {
                $currentLines[] = $line;

                continue;
            }

            $leftover[] = $line;
        }

        $flush();

        $leftoverText = trim(implode("\n", $leftover));
        $changesText = $leftoverText === '' ? $before : rtrim($before) . "\n\n" . $leftoverText;

        return [$changesText, $replies];
    }

    /**
     * A real rewrite starts with "## Summary" (per the follow-up prompt), so
     * anything whose first non-empty line merely starts with "unchanged" is
     * treated as unchanged, trailer sentence and all.
     */
    private function isUnchanged(string $description): bool
    {
        $firstLine = strtok($description, "\n");

        return $firstLine !== false && preg_match('/^unchanged\b/i', trim($firstLine)) === 1;
    }

    /**
     * Strips any marker the agent echoed back verbatim from its own output
     * (e.g. copying the wrapped block it was shown as an example). Left in
     * place, a stray end marker would give a section two end markers and
     * corrupt later replacements.
     */
    private function stripStrayMarkers(string $text): string
    {
        return preg_replace('/<!-- \/?yak:[a-z]+ -->/', '', $text) ?? $text;
    }

    /**
     * @return array{start: int, end: int}|null byte offsets of the heading line and of the first byte after it
     */
    private function headingOffset(string $text, string $title): ?array
    {
        $pattern = '/^##[ \t]+' . preg_quote($title, '/') . '[ \t]*\r?$/im';

        if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = (int) $match[0][1];
        $end = $start + strlen($match[0][0]);

        return ['start' => $start, 'end' => $end];
    }

    private function stripChangesHeading(string $text): string
    {
        $offset = $this->headingOffset($text, self::CHANGES_HEADING);

        if ($offset === null) {
            return $text;
        }

        return substr($text, 0, $offset['start']) . substr($text, $offset['end']);
    }
}
