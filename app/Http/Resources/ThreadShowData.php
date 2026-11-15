<?php

namespace App\Http\Resources;

use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Teleport\LocalTurns;

/**
 * Props of the `Threads/Show` page, shared by the web page and the app's thread show.
 */
final class ThreadShowData
{
    /**
     * @return array<string, mixed> `ThreadPageData::build()` merged with messages, localTurns, walkthrough, sharedFiles, composer, notice, modelLabels and stackParent
     */
    public static function build(Thread $thread, ?User $viewer): array
    {
        return array_merge(ThreadPageData::build($thread, $viewer), [
            'messages' => ThreadMessagesData::for($thread),
            'localTurns' => app(LocalTurns::class)->summary($thread),
            'walkthrough' => ThreadWalkthroughData::for($thread),
            'sharedFiles' => ThreadSharedFilesData::for($thread),
            'composer' => ThreadComposerData::for($thread, $viewer),
            'notice' => ThreadNoticeData::for($thread, $viewer),
            'modelLabels' => self::modelLabels(),
            'stackParent' => self::stackParent($thread),
        ]);
    }

    /**
     * The catalog label of every exact model id and family alias, so a raw id reads as "Sonnet 5.5".
     *
     * @return array<string, string>
     */
    private static function modelLabels(): array
    {
        $catalog = app(ClaudeModelCatalog::class);
        $labels = array_column($catalog->entries(), 'label', 'id');

        foreach ($catalog->families() as $family) {
            $labels[$family['family']] = $family['latest']['label'];
        }

        return $labels;
    }

    /**
     * @return array{id: string, name: string, title: string}|null
     */
    private static function stackParent(Thread $thread): ?array
    {
        $parent = $thread->parent_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($thread->parent_thread_id);

        return $parent === null ? null : ['id' => $parent->public_id, 'name' => $parent->name, 'title' => $parent->title];
    }
}
