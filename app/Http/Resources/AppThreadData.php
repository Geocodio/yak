<?php

namespace App\Http\Resources;

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;

/**
 * Thread show for the app: the web page props plus the panes it offers, the open question and the latest event sequence.
 */
final class AppThreadData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Thread $thread, ?User $viewer): array
    {
        $data = ThreadShowData::build($thread, $viewer);
        $hasNoSandbox = $thread->sandbox_state === ThreadSandboxState::None;

        return $data + [
            'panes' => [
                'chat' => true,
                'preview' => $data['thread']['preview']['state'] !== 'no_manifest',
                'terminal' => ! $hasNoSandbox,
                'files' => ! $hasNoSandbox,
                'changes' => true,
            ],
            'question' => ThreadQuestionData::for($thread),
            'latestSequence' => (int) Thread::withoutGlobalScopes()->whereKey($thread->id)->value('event_sequence'),
        ];
    }
}
