<?php

namespace App\DataTransferObjects;

use App\Models\YakTask;
use Illuminate\Support\Carbon;

/**
 * One renderable entry in the task conversation thread.
 *
 * Kinds: 'user' (a request/reply/steering message), 'yak' (a run: work
 * summary + result/error), 'clarification' (Yak asking a question),
 * 'clarification-answers' (the answers to a round of questions),
 * 'system' (thin line: retry, expiry, re-review, reroute).
 */
readonly class ThreadEntry
{
    /**
     * @param  array<int, string>  $options  clarification options ('clarification' kind)
     * @param  array<string, int|string|null>  $runStats  ['steps' => int, 'attempt' => int, 'duration_ms' => int|null]
     * @param  array<int, int>|null  $attachmentIds  Files sent with a clarification reply; null for messages whose files are the run's own
     * @param  list<array{header: string, answer: string|null, other: string|null, skipped: bool}>  $answerItems  per-question answers ('clarification-answers' kind)
     */
    private function __construct(
        public string $kind,
        public ?YakTask $run,
        public string $text,
        public ?string $summary,
        public Carbon $timestamp,
        public ?string $source,
        public array $options = [],
        public array $runStats = [],
        public bool $isLive = false,
        public ?string $error = null,
        public ?string $authorName = null,
        public ?array $attachmentIds = null,
        public array $answerItems = [],
    ) {}

    public static function user(YakTask $run, string $text, ?string $summary, Carbon $at, ?string $source, ?string $authorName = null): self
    {
        return new self('user', $run, $text, $summary, $at, $source, authorName: $authorName);
    }

    /**
     * A person's answer to a clarification question, shown as their message.
     *
     * @param  array<int, int>  $attachmentIds
     */
    public static function clarificationReply(YakTask $run, string $text, Carbon $at, ?string $authorName, array $attachmentIds): self
    {
        return new self('user', $run, $text, null, $at, 'dashboard', authorName: $authorName, attachmentIds: $attachmentIds);
    }

    /**
     * @param  array<string, int|string|null>  $runStats  ['steps' => int, 'attempt' => int, 'duration_ms' => int|null]
     */
    public static function yak(YakTask $run, string $text, Carbon $at, array $runStats, bool $isLive, ?string $error): self
    {
        return new self('yak', $run, $text, null, $at, null, [], $runStats, $isLive, $error);
    }

    /**
     * @param  array<int, string>  $options
     */
    public static function clarification(YakTask $run, string $text, array $options, Carbon $at): self
    {
        return new self('clarification', $run, $text, null, $at, null, $options);
    }

    /**
     * @param  list<array{header: string, answer: string|null, other: string|null, skipped: bool}>  $answerItems
     */
    public static function clarificationAnswers(YakTask $run, array $answerItems, ?string $note, string $authorName, Carbon $at): self
    {
        return new self('clarification-answers', $run, (string) $note, null, $at, null, authorName: $authorName, answerItems: $answerItems);
    }

    public static function system(string $text, Carbon $at): self
    {
        return new self('system', null, $text, null, $at, null);
    }
}
