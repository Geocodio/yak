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
     * @return list<array{id: string, name: string, kind: 'report'|'image'|'csv'|'pdf'|'text'|'file', sizeBytes: int, url: string, sharedWithTeam: bool, anchorSequence: ?int, createdAt: string}>
     */
    public static function for(Thread $thread): array
    {
        return Artifact::query()
            ->shared()
            ->whereIn('yak_task_id', $thread->tasks()->select('id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Artifact $file): array => [
                'id' => (string) $file->public_id,
                'name' => $file->filename,
                'kind' => $file->sharedKind(),
                'sizeBytes' => (int) $file->size_bytes,
                'url' => route('shared-files.show', ['publicId' => $file->public_id]),
                'sharedWithTeam' => $file->shared_with_team,
                'anchorSequence' => $file->anchor_sequence === null ? null : (int) $file->anchor_sequence,
                'createdAt' => $file->created_at?->toIso8601String() ?? '',
            ])
            ->all();
    }
}
