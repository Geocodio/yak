<?php

namespace App\Jobs\Threads;

use App\Enums\HeldEventKind;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Teleport\HeldEvents;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\PullRequests\RepositoryTurnPrompts;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Starts a turn on a thread for an event on one repo's pull request. The
 * prompt names the repo. A running or waiting turn is steered instead.
 */
class StartRepositoryTurnJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $threadRepositoryId,
        public readonly string $prompt,
        public readonly ?int $githubCommentId = null,
    ) {
        $this->onQueue('yak-claude');
    }

    public function handle(ThreadTurns $turns, HeldEvents $heldEvents): void
    {
        $row = ThreadRepository::query()->find($this->threadRepositoryId);
        $thread = $row === null ? null : Thread::query()->withoutGlobalScopes()->find($row->thread_id);
        $headTask = $thread === null ? null : YakTask::query()->where('thread_id', $thread->id)->latest('id')->first();

        if ($row === null || $thread === null || $headTask === null || ! $row->isChangesRepo()) {
            return;
        }

        if ($heldEvents->holdIfDeviceOwned(
            $headTask,
            HeldEventKind::ReviewComment,
            'Comment on ' . RepositoryTurnPrompts::pullRequestReference($row),
            Str::limit($this->prompt, 500),
            ['repo' => $row->repo, 'github_comment_id' => $this->githubCommentId],
            $row->pull_request_url,
        )) {
            return;
        }

        try {
            if (ThreadTurns::activeTurn($thread) !== null) {
                $turns->steer($thread, $this->prompt, null, null);

                return;
            }

            $turns->start($headTask, $this->prompt, null, null);
        } catch (ThreadBusy|ThreadNotOwnedByYak) {
            $this->release(30);
        }
    }
}
