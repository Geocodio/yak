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

        // Extracted before the "What changed" heading is stripped, so a
        // "## Replies" section placed ahead of "## What changed in this
        // run" is bounded at that heading rather than running to the end
        // of the text and swallowing it.
        [$withoutReplies, $replies] = $this->extractReplies($changesText);

        $changes = $this->stripStrayMarkers($this->stripChangesHeading($withoutReplies));

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
     * Pulls `- [c:<id>] ...` entries out of the changes text. A "## Replies"
     * heading bounds the sweep to that section, running to the next
     * second-level heading or the end of the text, so a Replies section
     * placed before "What changed" cannot swallow it. Without that heading
     * the whole text is swept for tagged entries instead, which also picks
     * up entries sitting under a lower-level "### Replies" / "**Replies**"
     * heading or no heading at all.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function extractReplies(string $text): array
    {
        $offset = $this->headingOffset($text, self::REPLIES_HEADING);

        if ($offset === null) {
            return $this->sweepTaggedEntries($text, stripBareHeading: true);
        }

        $before = rtrim(substr($text, 0, $offset['start']));
        $sectionEnd = $this->nextTopHeadingOffset($text, $offset['end']);
        $section = substr($text, $offset['end'], $sectionEnd - $offset['end']);
        $after = trim(substr($text, $sectionEnd));

        [$leftover, $replies] = $this->sweepTaggedEntries($section);

        $changes = trim(implode("\n\n", array_filter(
            [$before, $leftover, $after],
            fn (string $part): bool => $part !== '',
        )));

        return [$changes, $replies];
    }

    /**
     * Walks a block of text line by line, pulling `- [c:<id>] ...` entries
     * out into `$replies` and leaving everything else in the returned
     * string. An entry runs until the next tagged entry or the next
     * Markdown heading (any level), so prose and headings after an entry
     * are never swallowed into its body. A block with no tagged entries at
     * all is returned unchanged, byte for byte, so text with no replies
     * never has its line endings normalised.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function sweepTaggedEntries(string $text, bool $stripBareHeading = false): array
    {
        if (preg_match('/^\s*-\s*\[c:\d+\]/m', $text) !== 1) {
            return [$text, []];
        }

        $replies = [];
        $leftover = [];
        $currentId = null;
        $currentLines = [];
        $sawFirstEntry = false;

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*-\s*\[c:(\d+)\]\s*(.*)$/', $line, $match) === 1) {
                $this->flushReply($replies, $currentId, $currentLines);

                if ($stripBareHeading && ! $sawFirstEntry) {
                    $sawFirstEntry = true;
                    $this->stripBareRepliesHeading($leftover);
                }

                $currentId = (int) $match[1];
                $currentLines = [$match[2]];

                continue;
            }

            if ($currentId !== null && preg_match('/^#{1,6}[ \t]/', $line) === 1) {
                $this->flushReply($replies, $currentId, $currentLines);
                $leftover[] = $line;

                continue;
            }

            if ($currentId !== null) {
                $currentLines[] = $line;

                continue;
            }

            $leftover[] = $line;
        }

        $this->flushReply($replies, $currentId, $currentLines);

        return [trim(implode("\n", $leftover)), $replies];
    }

    /**
     * Removes the last non-blank leftover line when it is a bare "Replies"
     * label (`### Replies`, `**Replies**`, `Replies:`) with nothing else on
     * it. `headingOffset()` only recognises `## Replies`, so a lower-level
     * or bold label would otherwise leak into the changes text once the
     * entries below it are pulled out.
     *
     * @param  array<int, string>  $leftover
     */
    private function stripBareRepliesHeading(array &$leftover): void
    {
        $index = count($leftover) - 1;

        while ($index >= 0 && trim($leftover[$index]) === '') {
            $index--;
        }

        if ($index < 0) {
            return;
        }

        if (preg_match('/^(#{1,6}\s*Replies\s*:?|\*\*Replies\*\*:?|Replies:)\s*$/i', trim($leftover[$index])) === 1) {
            array_splice($leftover, $index, 1);
        }
    }

    /**
     * Commits the reply lines gathered for the current tagged entry, if
     * any, into `$replies` and resets the entry so the next tag starts
     * clean.
     *
     * @param  array<int, string>  $replies
     *
     * @param-out  null  $currentId
     *
     * @param  array<int, string>  $currentLines
     */
    private function flushReply(array &$replies, ?int &$currentId, array &$currentLines): void
    {
        if ($currentId !== null) {
            $replies[$currentId] = trim(implode("\n", array_map(trim(...), $currentLines)));
        }

        $currentId = null;
        $currentLines = [];
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

    /**
     * Byte offset of the next second-level ("## ") heading at or after
     * `$from`, or the end of the text when there is none. Bounds a
     * "## Replies" section so it cannot run past the next heading and
     * swallow the sections that follow it.
     */
    private function nextTopHeadingOffset(string $text, int $from): int
    {
        if (preg_match('/^##[ \t]/m', $text, $match, PREG_OFFSET_CAPTURE, $from) === 1) {
            return (int) $match[0][1];
        }

        return strlen($text);
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
