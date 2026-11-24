<?php

namespace App\Http\Resources;

use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use App\Services\Tasks\AppMediaLinks;
use App\Services\Threads\PullRequests\MergeOrder;
use App\Services\Threads\PullRequests\OpenPullRequest;

/**
 * Thread show for the app: the web page props plus the panes it offers, the open question and the latest event sequence.
 *
 * @phpstan-import-type ThreadShow from ThreadShowData
 */
final class AppThreadData
{
    /**
     * Panes, question, latestSequence, canOpenPullRequest, liveActivityChannel and pullRequests extend the ThreadShow shape.
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
            'pullRequests' => self::pullRequests($thread),
        ];
    }

    /**
     * Every pull request of the thread's set in the web's merge order, read from the stored rows the web panel reads.
     * `state` is open, merged or closed (draft is not stored, so it is never sent); `checks` is passing, failing,
     * pending or null when no checks were seen.
     *
     * @return list<array{number: int, url: string, repository: string, state: 'open'|'merged'|'closed', checks: 'passing'|'failing'|'pending'|null}>
     */
    private static function pullRequests(Thread $thread): array
    {
        $pullRequests = [];

        foreach (app(MergeOrder::class)->forThread($thread) as $row) {
            if ($row->pull_request_number === null) {
                continue;
            }

            $pullRequests[] = [
                'number' => (int) $row->pull_request_number,
                'url' => (string) $row->pull_request_url,
                'repository' => Repository::githubNameFor($row->repo),
                'state' => match ($row->pull_request_state) {
                    'merged' => 'merged',
                    'closed' => 'closed',
                    default => 'open',
                },
                'checks' => AppPullRequestData::checks($row->ci_status),
            ];
        }

        return $pullRequests;
    }
}
