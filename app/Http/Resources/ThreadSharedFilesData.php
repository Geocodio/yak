<?php

namespace App\Http\Resources;

use App\Models\Artifact;
use App\Models\Thread;

/**
 * The files Claude made for the person on a thread, oldest first. The array shape below is the source of truth
 * for `SharedFile` in `resources/js/types/threads.ts`.
 */
final class ThreadSharedFilesData
{
    /**
     * @return list<array{id: string, name: string, kind: 'report'|'image'|'csv'|'pdf'|'text'|'file', sizeBytes: int, url: string, sharedWithTeam: bool, anchorSequence: ?int, createdAt: string, updatedAt: ?string, versions: list<array{version: int, anchorSequence: ?int, createdAt: string, sizeBytes: int}>}>
     */
    public static function for(Thread $thread): array
    {
        $files = Artifact::query()
            ->shared()
            ->whereIn('yak_task_id', $thread->tasks()->select('id'))
            ->with('versions')
            ->orderBy('id')
            ->get();
        $shared = [];

        foreach ($files as $file) {
            $shared[] = [
                'id' => (string) $file->public_id,
                'name' => $file->filename,
                'kind' => $file->sharedKind(),
                'sizeBytes' => (int) $file->size_bytes,
                'url' => route('shared-files.show', ['publicId' => $file->public_id]),
                'sharedWithTeam' => $file->shared_with_team,
                'anchorSequence' => $file->anchor_sequence === null ? null : (int) $file->anchor_sequence,
                'createdAt' => $file->created_at?->toIso8601String() ?? '',
                'updatedAt' => $file->versions->last()?->created_at?->toIso8601String(),
                'versions' => $file->versions->map(fn ($version): array => [
                    'version' => (int) $version->version,
                    'anchorSequence' => $version->anchor_sequence === null ? null : (int) $version->anchor_sequence,
                    'createdAt' => $version->created_at?->toIso8601String() ?? '',
                    'sizeBytes' => (int) $version->size_bytes,
                ])->all(),
            ];
        }

        return $shared;
    }
}
