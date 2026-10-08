<?php

namespace App\Http\Resources;

use App\Models\TaskAttachment;

/**
 * Flattens a {@see TaskAttachment} into the shape the task thread renders.
 * The array shape below is the source of truth for `AttachmentData` in
 * `resources/js/types/tasks.ts`.
 */
final class AttachmentData
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     reference: ?string,
     *     url: string,
     *     mimeType: string,
     *     size: int,
     *     isImage: bool,
     *     previewKind: 'image'|'video'|'audio'|'pdf'|'text'|null,
     *     downloadUrl: string,
     * }
     */
    public static function from(TaskAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'reference' => $attachment->reference,
            'url' => $attachment->url(),
            'mimeType' => $attachment->mime_type,
            'size' => (int) $attachment->size_bytes,
            'isImage' => $attachment->isImage(),
            'previewKind' => $attachment->previewKind(),
            'downloadUrl' => $attachment->downloadUrl(),
        ];
    }
}
