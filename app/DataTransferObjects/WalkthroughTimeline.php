<?php

namespace App\DataTransferObjects;

use RuntimeException;

/**
 * The shape of a v3 cut as computed by `video/scripts/timeline.ts`.
 *
 * Remotion components run in headless Chrome and cannot write files, so
 * the host asks the same `buildBlocks()` for the timeline *before*
 * rendering: chapters.json, the expected duration, and the caption-fit
 * measurements all come from here (spec §7).
 *
 * @phpstan-type TimelineBlock array{kind: string, id: string, startSeconds: float, durationSeconds: float}
 * @phpstan-type TimelineChapter array{title: string, startSeconds: float, shots: array<int, array{id: string, startSeconds: float, say: string}>}
 */
final readonly class WalkthroughTimeline
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<int, TimelineChapter>  $chapters
     * @param  array<int, array{shotId: string, width: float}>  $captionOverflow
     */
    public function __construct(
        public int $fps,
        public int $width,
        public int $height,
        public float $durationSeconds,
        public int $durationInFrames,
        public array $blocks,
        public array $chapters,
        public array $captionOverflow,
    ) {}

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['durationSeconds'], $decoded['blocks'])) {
            throw new RuntimeException('timeline.ts produced no usable JSON: ' . mb_substr($json, 0, 300));
        }

        return new self(
            fps: (int) ($decoded['fps'] ?? 30),
            width: (int) ($decoded['width'] ?? 0),
            height: (int) ($decoded['height'] ?? 0),
            durationSeconds: (float) $decoded['durationSeconds'],
            durationInFrames: (int) ($decoded['durationInFrames'] ?? 0),
            blocks: array_values((array) $decoded['blocks']),
            chapters: array_values((array) ($decoded['chapters'] ?? [])),
            captionOverflow: array_values((array) ($decoded['captionOverflow'] ?? [])),
        );
    }

    public function firstShotStartSeconds(): ?float
    {
        $shots = $this->shotBlocks();

        return $shots === [] ? null : $shots[0]['startSeconds'];
    }

    /**
     * @return array<int, array{id: string, startSeconds: float, durationSeconds: float}>
     */
    public function shotBlocks(): array
    {
        $shots = [];

        foreach ($this->blocks as $block) {
            if (($block['kind'] ?? null) !== 'shot') {
                continue;
            }

            $shots[] = [
                'id' => (string) ($block['id'] ?? ''),
                'startSeconds' => (float) ($block['startSeconds'] ?? 0),
                'durationSeconds' => (float) ($block['durationSeconds'] ?? 0),
            ];
        }

        return $shots;
    }

    /**
     * Three quarters into each shot block: past the action, inside the
     * hold, so the frame the QA gate samples is the one a viewer reads.
     *
     * @return array<int, array{id: string, seconds: float}>
     */
    public function midHoldSeconds(): array
    {
        return array_map(
            fn (array $shot): array => [
                'id' => $shot['id'],
                'seconds' => round($shot['startSeconds'] + $shot['durationSeconds'] * 0.75, 3),
            ],
            $this->shotBlocks(),
        );
    }
}
