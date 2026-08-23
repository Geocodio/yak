<?php

namespace App\DataTransferObjects;

use App\Models\YakTask;
use Illuminate\Support\Carbon;

/**
 * One renderable entry in the task conversation thread.
 *
 * Kinds: 'user' (a request/reply/steering message), 'yak' (a run: work
 * summary + result/error), 'clarification' (Yak asking a question),
 * 'system' (thin line: retry, expiry, re-review, reroute).
 */
readonly class ThreadEntry
{
    /**
     * @param  array<int, string>  $options  clarification options ('clarification' kind)
     * @param  array<string, int|string|null>  $runStats  ['steps' => int, 'attempt' => int, 'duration_ms' => int|null]
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
    ) {}

    public static function user(YakTask $run, string $text, ?string $summary, Carbon $at, ?string $source): self
    {
        return new self('user', $run, $text, $summary, $at, $source);
    }

    public static function yak(YakTask $run, string $text, Carbon $at, array $runStats, bool $isLive, ?string $error): self
    {
        return new self('yak', $run, $text, null, $at, null, [], $runStats, $isLive, $error);
    }

    public static function clarification(YakTask $run, string $text, array $options, Carbon $at): self
    {
        return new self('clarification', $run, $text, null, $at, null, $options);
    }

    public static function system(string $text, Carbon $at): self
    {
        return new self('system', null, $text, null, $at, null);
    }
}
