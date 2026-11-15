<?php

namespace App\Services\Push\Widgets;

use App\Enums\ThreadListFilter;
use App\Models\User;
use App\Services\Threads\NeedsYouCount;
use App\Services\Threads\ThreadList;

/**
 * What a person's widgets show, as one hash: the needs-you count and the public ids of every running thread.
 * It reads the same list and count as `GET threads?filter=mine` and the badge, so the widget and the badge never disagree.
 */
final class WidgetFingerprint
{
    public static function for(User $user): string
    {
        $list = ThreadList::for($user, ThreadListFilter::Mine);
        $running = [];

        foreach ([...$list['starred'], ...$list['needsYou'], ...$list['threads']] as $row) {
            foreach ([$row, ...$row['children']] as $thread) {
                if ($thread['status'] === 'running') {
                    $running[] = $thread['id'];
                }
            }
        }

        sort($running);

        return hash('sha256', json_encode([
            'needsYouCount' => NeedsYouCount::for($user, $list['needsYouCount']),
            'running' => $running,
        ], JSON_THROW_ON_ERROR));
    }
}
