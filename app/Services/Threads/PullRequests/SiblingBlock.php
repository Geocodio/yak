<?php

namespace App\Services\Threads\PullRequests;

use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;

/**
 * The "Related pull requests" text written into every pull request of a
 * multi-repo thread. It never carries the thread title or conversation.
 */
class SiblingBlock
{
    public function render(Thread $thread, ThreadRepository $forRow): ?string
    {
        $rows = ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('role', '!=', 'context')
            ->whereNotNull('pull_request_url')
            ->orderByRaw('merge_position is null, merge_position')
            ->orderBy('id')
            ->get();

        if ($rows->count() < 2) {
            return null;
        }

        $showTitles = ! $thread->isPrivate();
        $lines = ['### Related pull requests', '', "This change spans {$rows->count()} repositories. Merge in this order:", ''];

        $previous = null;

        foreach ($rows->values() as $index => $row) {
            $line = ($index + 1) . '. ' . RepositoryTurnPrompts::pullRequestReference($row);

            if ($showTitles && trim((string) $row->pull_request_title) !== '') {
                $line .= ' ' . str_replace(["\r", "\n"], ' ', (string) $row->pull_request_title);
            }

            if ($row->is($forRow)) {
                $line .= ' (this pull request)';
            }

            if ($row->hasEnded()) {
                $line .= " ({$row->pull_request_state})";
            }

            $lines[] = $line;

            if ($previous !== null) {
                $lines[] = '   Merge after ' . RepositoryTurnPrompts::pullRequestReference($previous);
            }

            $previous = $row;
        }

        $context = ThreadRepository::query()->where('thread_id', $thread->id)->where('role', 'context')->orderBy('id')->pluck('repo')
            ->map(fn (string $slug): string => Repository::githubNameFor($slug));

        if ($context->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Also used as context, with no pull request: ' . $context->implode(', ') . '.';
        }

        $lines[] = '';
        $lines[] = 'Merge order is a suggestion. Yak does not merge pull requests.';

        return implode("\n", $lines);
    }
}
