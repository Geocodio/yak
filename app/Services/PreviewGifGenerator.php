<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Cuts `walkthrough-preview.gif` out of the rendered mp4: the title card
 * plus the opening of the first shot (spec §8). GitHub renders a GIF
 * inline in a PR body, so a reviewer sees motion without leaving the PR —
 * as long as it stays under camo's size limit, which the cap enforces.
 */
class PreviewGifGenerator
{
    public const float TITLE_SECONDS = 1.5;

    public const float SHOT_SECONDS = 6.0;

    /** 2.5 MiB — comfortably inside GitHub camo's 5 MB ceiling. */
    public const int MAX_BYTES = 2_621_440;

    public function generate(string $mp4Path, string $outputPath, ?float $firstShotStartSeconds): string
    {
        $ranges = $this->ranges($firstShotStartSeconds);

        $this->encode($mp4Path, $outputPath, $ranges, width: 720, fps: 12);

        if (file_exists($outputPath) && filesize($outputPath) > self::MAX_BYTES) {
            $this->encode($mp4Path, $outputPath, $ranges, width: 640, fps: 10);
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException("preview gif was not produced: {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function ranges(?float $firstShotStartSeconds): array
    {
        if ($firstShotStartSeconds === null) {
            return [[0.0, self::TITLE_SECONDS + self::SHOT_SECONDS]];
        }

        return [
            [0.0, self::TITLE_SECONDS],
            [$firstShotStartSeconds, $firstShotStartSeconds + self::SHOT_SECONDS],
        ];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ranges
     */
    private function encode(string $mp4Path, string $outputPath, array $ranges, int $width, int $fps): void
    {
        $select = implode('+', array_map(
            fn (array $range): string => 'between(t,' . $this->seconds($range[0]) . ',' . $this->seconds($range[1]) . ')',
            $ranges,
        ));

        $base = "select='{$select}',setpts=N/FRAME_RATE/TB,fps={$fps},scale={$width}:-1:flags=lanczos";
        $palette = sys_get_temp_dir() . '/yak-palette-' . bin2hex(random_bytes(6)) . '.png';

        try {
            $this->run(['ffmpeg', '-y', '-i', $mp4Path, '-vf', $base . ',palettegen=stats_mode=diff', $palette]);
            $this->run([
                'ffmpeg', '-y', '-i', $mp4Path, '-i', $palette,
                '-lavfi', $base . ' [x]; [x][1:v] paletteuse=dither=bayer:bayer_scale=3',
                '-loop', '0', $outputPath,
            ]);
        } finally {
            File::delete($palette);
        }
    }

    /** @param array<int, string> $command */
    private function run(array $command): void
    {
        $result = Process::timeout(180)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                "preview gif ffmpeg pass failed (exit {$result->exitCode()}): " . trim($result->errorOutput())
            );
        }
    }

    /** Trims trailing zeros so `8.0` reads as `8` inside the filter string. */
    private function seconds(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
