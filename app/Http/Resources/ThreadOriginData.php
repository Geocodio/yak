<?php

namespace App\Http\Resources;

use App\Models\Thread;
use App\Services\Threads\ThreadOrigin;

/**
 * The source and starter fields every thread row carries.
 */
final class ThreadOriginData
{
    /**
     * @return array{
     *     source: array{key: string, label: string, reference: ?string, url: ?string},
     *     starter: array{key: string, name: string, avatarUrl: ?string},
     * }
     */
    public static function for(Thread $thread): array
    {
        $key = app(ThreadOrigin::class)->starterKey($thread);
        $creator = $thread->created_by_user_id !== null ? $thread->creator : null;
        $avatarUrl = $creator?->getAttribute('avatar_url');

        return [
            'source' => [
                'key' => $thread->source->value,
                'label' => $thread->source->label(),
                'reference' => $thread->source_reference,
                'url' => $thread->source_url,
            ],
            'starter' => [
                'key' => $key,
                'name' => $creator?->name ?? $thread->starter_name ?? 'Yak',
                'avatarUrl' => is_string($avatarUrl) ? $avatarUrl : null,
            ],
        ];
    }
}
