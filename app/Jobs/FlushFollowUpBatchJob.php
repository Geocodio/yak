<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\FollowUpPendingComment;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class FlushFollowUpBatchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(
        public readonly string $prUrl,
    ) {
        $this->onQueue('default');
    }

    public function handle(FollowUpTaskFactory $factory, AppService $gitHub): void
    {
        $comments = FollowUpPendingComment::where('pr_url', $this->prUrl)->orderBy('id')->get();

        if ($comments->isEmpty()) {
            return;
        }

        // Resolve the conversation root (or any task) for this PR.
        $parent = YakTask::where('pr_url', $this->prUrl)->whereNull('parent_task_id')->first()
            ?? YakTask::where('pr_url', $this->prUrl)->first();

        if ($parent === null) {
            FollowUpPendingComment::where('pr_url', $this->prUrl)->delete();

            return;
        }

        $instructions = $this->composeInstructions($comments);

        // Clear the buffer BEFORE running so comments arriving during the run
        // start a fresh batch (the next run serializes behind this one via the
        // branch-overlap middleware on RunFollowUpJob).
        FollowUpPendingComment::where('pr_url', $this->prUrl)->delete();

        $child = $factory->create($parent, $instructions, 'github');

        if ($child === null) {
            // PR merged/closed — decline politely.
            $installationId = (int) config('yak.channels.github.installation_id');
            $prNumber = (int) ($parent->pr_number ?? 0);

            if ($installationId > 0 && $prNumber > 0) {
                $gitHub->commentOnPullRequest(
                    $installationId,
                    (string) $parent->repo,
                    $prNumber,
                    "This PR is already merged or closed, so I can't push more changes here. Open a new issue or task and I'll pick it up.",
                );
            }
        }
    }

    /**
     * @param  Collection<int, FollowUpPendingComment>  $comments
     */
    private function composeInstructions(Collection $comments): string
    {
        $lines = ['The following feedback was left on the pull request:', ''];

        foreach ($comments as $comment) {
            $anchor = $comment->file !== null
                ? "{$comment->file}" . ($comment->line !== null ? ":{$comment->line}" : '') . ' — '
                : '';

            $lines[] = "- {$anchor}{$comment->body}";

            if ($comment->diff_hunk !== null && $comment->diff_hunk !== '') {
                $lines[] = '';
                $lines[] = '  ```diff';
                foreach (explode("\n", $comment->diff_hunk) as $hunkLine) {
                    $lines[] = '  ' . $hunkLine;
                }
                $lines[] = '  ```';
            }
        }

        return implode("\n", $lines);
    }
}
