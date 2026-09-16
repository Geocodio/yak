<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\FollowUpPendingComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\ReviewFeedbackFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        // Capture the IDs of comments we're processing so we can delete only these
        // rows after create() succeeds. This ensures comments that arrive during the
        // run are not swept up and can be retried if create() throws.
        $ids = $comments->pluck('id')->all();

        // Resolve the conversation root (or any non-review task) for this PR.
        $parent = YakTask::followUpRootForPr($this->prUrl);

        if ($parent === null) {
            FollowUpPendingComment::whereIn('id', $ids)->delete();

            return;
        }

        $instructions = app(ReviewFeedbackFormatter::class)->format(
            '',
            '',
            $comments->map(fn (FollowUpPendingComment $comment): array => [
                'body' => (string) $comment->body,
                'author' => $comment->author,
                'file' => $comment->file,
                'line' => $comment->line !== null ? (int) $comment->line : null,
                'diff_hunk' => $comment->diff_hunk,
            ])->all(),
            '',
        );

        $authorName = $comments->pluck('author')->filter()->unique()->implode(', ') ?: null;

        $child = $factory->create($parent, $instructions, 'github', authorName: $authorName);

        if ($child === null) {
            // PR merged/closed — decline politely.
            $installationId = (int) config('yak.channels.github.installation_id');
            $prNumber = (int) ($parent->pr_number ?? 0);

            if ($installationId > 0 && $prNumber > 0) {
                $gitHub->commentOnPullRequest(
                    $installationId,
                    Repository::githubNameFor((string) $parent->repo),
                    $prNumber,
                    "This PR is already merged or closed, so I can't push more changes here. Open a new issue or task and I'll pick it up.",
                );
            }
        }

        // Delete only the buffered comments we processed. Comments that arrived
        // during the run have new IDs not in $ids, so they survive for the next batch.
        FollowUpPendingComment::whereIn('id', $ids)->delete();
    }
}
