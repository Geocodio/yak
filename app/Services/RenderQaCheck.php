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
            /** @var array<int, array{id: string, hash: string}> $hashes */
            $hashes = [];

            foreach ($samples as $sample) {
                $framePath = sys_get_temp_dir() . '/yak-qa-' . bin2hex(random_bytes(6)) . '.jpg';
                $frames[] = $framePath;

                Process::timeout(60)->run([
                    'ffmpeg', '-y', '-ss', (string) $sample['seconds'], '-i', $mp4Path,
                    '-frames:v', '1', '-q:v', '5', $framePath,
                ]);

                $hash = PerceptualHash::dhash($framePath);

                if ($hash === null) {
                    continue;
                }

                $hashes[] = ['id' => $sample['id'], 'hash' => $hash];
            }

            return $this->firstAllNeighbourMatch($hashes);
        } finally {
            foreach ($frames as $framePath) {
                @unlink($framePath);
            }
        }
    }

    /**
     * @param  array<int, array{id: string, hash: string}>  $hashes
     */
    private function firstAllNeighbourMatch(array $hashes): ?string
    {
        $count = count($hashes);

        if ($count < 2) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $previous = $i > 0 ? $hashes[$i - 1] : null;
            $next = $i + 1 < $count ? $hashes[$i + 1] : null;
            $hash = $hashes[$i]['hash'];

            $matchesPrevious = $previous !== null && PerceptualHash::hamming($hash, $previous['hash']) === 0;
            $matchesNext = $next !== null && PerceptualHash::hamming($hash, $next['hash']) === 0;

            if ($previous !== null && $next !== null && $matchesPrevious && $matchesNext) {
                return $this->identicalMessage($hashes[$i]['id'], $previous['id']);
            }

            if ($previous !== null && $next === null && $matchesPrevious) {
                return $this->identicalMessage($hashes[$i]['id'], $previous['id']);
            }

            if ($next !== null && $previous === null && $matchesNext) {
                return $this->identicalMessage($hashes[$i]['id'], $next['id']);
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
