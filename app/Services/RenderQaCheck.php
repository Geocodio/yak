<?php

namespace App\Services;

use App\DataTransferObjects\WalkthroughTimeline;
use Illuminate\Support\Facades\Process;

/**
 * The gate between a finished render and a reviewer (spec §7).
 *
 * A cut that is too short, too long, off its own timeline, carrying a
 * caption that spills its box, or repeating the same frame for every shot
 * never reaches a human. The first failing check wins, so the reason
 * names the most fundamental problem rather than a symptom of it.
 */
class RenderQaCheck
{
    /**
     * Fraction the rendered duration may drift from the timeline's before
     * the cut is treated as a different video than the one planned.
     */
    private const DURATION_TOLERANCE = 0.10;

    public function __construct(public VideoRenderer $renderer) {}

    /**
     * @throws RenderQaFailure
     */
    public function assertPasses(string $mp4Path, WalkthroughTimeline $timeline): void
    {
        $reason = self::overflowReason($timeline->captionOverflow);

        if ($reason !== null) {
            throw new RenderQaFailure($reason);
        }

        $actual = $this->renderer->probeDurationSeconds($mp4Path);

        if ($actual === null) {
            throw new RenderQaFailure("could not probe the rendered video's duration");
        }

        $reason = self::boundsReason($actual);

        if ($reason !== null) {
            throw new RenderQaFailure($reason);
        }

        if (abs($actual - $timeline->durationSeconds) > $timeline->durationSeconds * self::DURATION_TOLERANCE) {
            throw new RenderQaFailure(sprintf(
                'rendered video is %ss but the timeline expected %ss (>10%% off)',
                number_format($actual, 1),
                number_format($timeline->durationSeconds, 1),
            ));
        }

        $reason = $this->identicalFramesReason($mp4Path, $timeline);

        if ($reason !== null) {
            throw new RenderQaFailure($reason);
        }
    }

    /**
     * Shared with the pre-render gate so the wording cannot drift.
     *
     * @param  array<int, array{shotId?: string, width?: float}>  $captionOverflow
     */
    public static function overflowReason(array $captionOverflow): ?string
    {
        if ($captionOverflow === []) {
            return null;
        }

        $ids = array_map(
            fn (array $overflow): string => (string) ($overflow['shotId'] ?? '?'),
            $captionOverflow,
        );

        return 'caption too long for its box: ' . implode(', ', $ids);
    }

    /**
     * Shared with the pre-render gate so the wording cannot drift.
     */
    public static function boundsReason(float $seconds): ?string
    {
        $bounds = array_values((array) config('yak.video.duration_bounds'));
        $min = (float) ($bounds[0] ?? 0);
        $max = (float) ($bounds[1] ?? 0);

        if ($seconds >= $min && $seconds <= $max) {
            return null;
        }

        return sprintf(
            'rendered video is %ss, outside the %s-%ss bounds',
            number_format($seconds, 1),
            self::trimZeros($min),
            self::trimZeros($max),
        );
    }

    /**
     * Sample one frame per shot at its mid-hold and compare each shot with
     * the neighbours it has. A shot only fails when it is identical to
     * *every* neighbour: that is a static or black cut, whereas two
     * legitimately similar consecutive shots on the same page still differ
     * from a third one further along.
     */
    private function identicalFramesReason(string $mp4Path, WalkthroughTimeline $timeline): ?string
    {
        $samples = $timeline->midHoldSeconds();

        if (count($samples) < 2) {
            return null;
        }

        $frames = [];

        try {
            /**
             * Index-aligned with $samples: a shot whose frame could not be
             * extracted holds null and keeps its place, so every comparison
             * below is against a shot's real timeline neighbour.
             *
             * @var array<int, string|null> $hashes
             */
            $hashes = [];

            foreach ($samples as $index => $sample) {
                $framePath = sys_get_temp_dir() . '/yak-qa-' . bin2hex(random_bytes(6)) . '.jpg';
                $frames[] = $framePath;

                Process::timeout(60)->run([
                    'ffmpeg', '-y', '-ss', (string) $sample['seconds'], '-i', $mp4Path,
                    '-frames:v', '1', '-q:v', '5', $framePath,
                ]);

                $hashes[$index] = PerceptualHash::dhash($framePath);
            }

            return $this->firstAllNeighbourMatch($samples, $hashes);
        } finally {
            foreach ($frames as $framePath) {
                @unlink($framePath);
            }
        }
    }

    /**
     * A missing hash is evidence of nothing: the shot it belongs to is
     * skipped, and it counts as absent when its neighbours look sideways —
     * exactly like the missing neighbour at either end of the timeline.
     *
     * @param  array<int, array{id: string, seconds: float}>  $samples
     * @param  array<int, string|null>  $hashes
     */
    private function firstAllNeighbourMatch(array $samples, array $hashes): ?string
    {
        $count = count($samples);

        for ($i = 0; $i < $count; $i++) {
            $hash = $hashes[$i] ?? null;

            if ($hash === null) {
                continue;
            }

            $matchedAll = true;
            $matchedNeighbourId = null;

            foreach ([$i - 1, $i + 1] as $neighbour) {
                $neighbourHash = $hashes[$neighbour] ?? null;

                if ($neighbourHash === null) {
                    continue;
                }

                if (PerceptualHash::hamming($hash, $neighbourHash) !== 0) {
                    $matchedAll = false;

                    break;
                }

                $matchedNeighbourId ??= $samples[$neighbour]['id'];
            }

            if ($matchedAll && $matchedNeighbourId !== null) {
                return $this->identicalMessage($samples[$i]['id'], $matchedNeighbourId);
            }
        }

        return null;
    }

    private function identicalMessage(string $shotId, string $neighbourId): string
    {
        return sprintf('shots %s and %s render identical frames', $shotId, $neighbourId);
    }

    /**
     * Bounds read as whole seconds in the message: `30-180s`, not `30.0-180.0s`.
     */
    private static function trimZeros(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.');
    }
}
