<?php

namespace App\Services\Threads\PullRequests;

use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\PullRequestBodySections;
use App\Services\PullRequestBodyUpdater;

/**
 * Writes the sibling block into the body of every open pull request of a
 * thread. Ended pull requests keep the body they had.
 */
class SiblingBlockPublisher
{
    public function __construct(
        private readonly SiblingBlock $block,
        private readonly PullRequestBodyUpdater $updater,
    ) {}

    public function publish(Thread $thread): void
    {
        $rows = ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('pull_request_state', 'open')
            ->whereNotNull('pull_request_number')
            ->get();

        foreach ($rows as $row) {
            $text = $this->block->render($thread, $row);

            if ($text === null) {
                continue;
            }

            $this->updater->setOrAppendSection(
                Repository::githubNameFor($row->repo),
                (int) $row->pull_request_number,
                PullRequestBodySections::SIBLINGS,
                PullRequestBodySections::wrap(PullRequestBodySections::SIBLINGS, $text),
            );
        }
    }
}
