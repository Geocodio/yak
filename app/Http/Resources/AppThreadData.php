<?php

namespace App\Http\Resources;

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use App\Services\Tasks\AppMediaLinks;
use App\Services\Threads\PullRequests\OpenPullRequest;

/**
 * Thread show for the app: the web page props plus the panes it offers, the open question and the latest event sequence.
 *
 * @phpstan-import-type ThreadShow from ThreadShowData
 */
final class AppThreadData
{
    /**
     * Panes, question, latestSequence, canOpenPullRequest and liveActivityChannel extend the ThreadShow shape.
     *
     * @return ThreadShow
     */
    public static function for(Thread $thread, User $viewer): array
    {
        // Read before the snapshot so catch-up from this sequence never skips an event; a replayed one is harmless.
        $latestSequence = (int) Thread::withoutGlobalScopes()->whereKey($thread->id)->value('event_sequence');
        $data = ThreadShowData::build($thread, $viewer, isTimeBounded: true);
        $attachmentIds = array_values(array_unique(array_merge(...array_column($data['messages'], 'attachmentIds') ?: [[]])));
        $links = AppMediaLinks::forThread($thread, $attachmentIds);
        // The editable `text` stays as typed so an edit never saves an expiring link.
        $data['messages'] = array_map(fn (array $message): array => [
            'attachments' => array_map(fn (array $attachment): array => $links->rewriteTree($attachment), $message['attachments']),
            'bodyHtml' => $links->rewrite($message['bodyHtml']),
        ] + $message, $data['messages']);
        $data['thread']['hasRepository'] = $thread->hasRepository();
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
            'latestSequence' => $latestSequence,
            'canOpenPullRequest' => app(OpenPullRequest::class)->canOpen($thread, $viewer),
            'liveActivityChannel' => app(LiveActivityChannels::class)->visibleFor($thread),
        ];
    }
}
