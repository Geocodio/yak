<?php

namespace App\Services;

use App\DataTransferObjects\WalkthroughTimeline;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Remuxes MP4 chapter metadata into the rendered walkthrough so QuickTime,
 * VLC and mpv show a chapter menu (spec §11). Chapter metadata is a
 * nicety: the PR's chapter line is read from chapters.json, not from the
 * mp4, so a failed remux is logged and swallowed rather than thrown.
 */
class Mp4ChapterWriter
{
    /**
     * Remux `$timeline`'s chapters into `$mp4Path` in place. A no-op when
     * the timeline has no chapters. Writes to a sibling temp file and
     * renames it over the original so a failed remux never leaves a
     * truncated mp4.
     */
    public function write(string $mp4Path, WalkthroughTimeline $timeline): void
    {
        if ($timeline->chapters === []) {
            return;
        }

        $ffmetaPath = sys_get_temp_dir() . '/yak-chapters-' . bin2hex(random_bytes(6)) . '.ffmeta';
        $tempMp4Path = $mp4Path . '.chapters.mp4';

        try {
            File::put($ffmetaPath, $this->ffmeta($timeline));

            $result = Process::run([
                'ffmpeg', '-y',
                '-i', $mp4Path,
                '-i', $ffmetaPath,
                '-map_metadata', '1',
                '-map_chapters', '1',
                '-codec', 'copy',
                $tempMp4Path,
            ]);

            if (! $result->successful()) {
                Log::channel('yak')->warning('mp4 chapter remux failed', [
                    'mp4Path' => $mp4Path,
                    'exitCode' => $result->exitCode(),
                    'error' => trim($result->errorOutput()),
                ]);

                File::delete($tempMp4Path);

                return;
            }

            rename($tempMp4Path, $mp4Path);
        } finally {
            File::delete($ffmetaPath);
        }
    }

    /**
     * Build the ffmetadata document ffmpeg expects: a `[CHAPTER]` block per
     * timeline chapter, each running from its own start to the next
     * chapter's start (or the timeline's duration for the last one).
     */
    public function ffmeta(WalkthroughTimeline $timeline): string
    {
        $lines = [';FFMETADATA1'];
        $chapters = $timeline->chapters;

        foreach ($chapters as $index => $chapter) {
            $endSeconds = $chapters[$index + 1]['startSeconds'] ?? $timeline->durationSeconds;

            $lines[] = '[CHAPTER]';
            $lines[] = 'TIMEBASE=1/1000';
            $lines[] = 'START=' . (int) round($chapter['startSeconds'] * 1000);
            $lines[] = 'END=' . (int) round($endSeconds * 1000);
            $lines[] = 'title=' . $this->escapeTitle($chapter['title']);
        }

        return implode("\n", $lines) . "\n";
    }

    private function escapeTitle(string $title): string
    {
        return str_replace(
            ['\\', '=', ';', '#', "\n"],
            ['\\\\', '\\=', '\;', '\\#', ' '],
            $title,
        );
    }
}
