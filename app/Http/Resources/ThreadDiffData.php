<?php

namespace App\Http\Resources;

use App\Enums\DiffFileStatus;
use App\Models\Thread;
use App\Services\Threads\ThreadTurns;

/**
 * Body of `threads.diff.show`. The array shape below is the source of truth for
 * `resources/js/types/thread-changes.ts`.
 */
final class ThreadDiffData
{
    /**
     * @return array{diff: null|array{
     *     baseSha: string,
     *     headSha: string,
     *     capturedAt: string,
     *     isTruncated: bool,
     *     filesCount: int,
     *     additions: int,
     *     deletions: int,
     *     isTurnRunning: bool,
     *     files: list<array{id: int, path: string, oldPath: ?string, status: value-of<DiffFileStatus>, additions: ?int, deletions: ?int, isBinary: bool, isTooLarge: bool}>,
     * }}
     */
    public static function build(Thread $thread): array
    {
        $diff = $thread->diff()->with('files')->first();

        if ($diff === null) {
            return ['diff' => null];
        }

        $files = [];

        foreach ($diff->files as $file) {
            $files[] = [
                'id' => $file->id,
                'path' => $file->path,
                'oldPath' => $file->old_path,
                'status' => $file->status->value,
                'additions' => $file->additions,
                'deletions' => $file->deletions,
                'isBinary' => $file->is_binary,
                'isTooLarge' => $file->is_too_large,
            ];
        }

        return ['diff' => [
            'baseSha' => $diff->base_sha,
            'headSha' => $diff->head_sha,
            'capturedAt' => $diff->captured_at->toIso8601String(),
            'isTruncated' => $diff->is_truncated,
            'filesCount' => $diff->files_count,
            'additions' => $diff->additions,
            'deletions' => $diff->deletions,
            'isTurnRunning' => ThreadTurns::runningTurn($thread) !== null,
            'files' => $files,
        ]];
    }
}
