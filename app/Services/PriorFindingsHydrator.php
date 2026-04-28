<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\PrReviewComment;
use Illuminate\Support\Facades\Log;

class PriorFindingsHydrator
{
    public function __construct(private readonly GitHubAppService $github) {}

    /**
     * Return prior unresolved findings ready to be inlined into the
     * incremental review prompt. An empty array means "skip the
     * Prior findings section entirely" — either there are none, or
     * the GraphQL fetch failed.
     *
     * @param  array<int, string>  $changedFiles
     * @return array<int, array{comment_id: int, file: string, line: int, severity: string, category: string, body: string, file_changed_in_this_push: bool}>
     */
    public function hydrate(string $repoSlug, int $prNumber, string $prUrl, array $changedFiles): array
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        try {
            $threads = $this->github->listReviewThreads($installationId, $repoSlug, $prNumber);
        } catch (\Throwable $e) {
            Log::warning('PriorFindingsHydrator: GraphQL fetch failed, dropping prior-findings section', [
                'pr_url' => $prUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $resolvedCommentIds = [];
        foreach ($threads as $thread) {
            if (! $thread['is_resolved']) {
                continue;
            }
            foreach ($thread['comment_database_ids'] as $cid) {
                $resolvedCommentIds[$cid] = true;
            }
        }

        $changedSet = array_flip($changedFiles);
        $cap = (int) config('yak.pr_review.max_findings_per_review', 20);

        $comments = PrReviewComment::query()
            ->whereHas('review', fn ($q) => $q->where('pr_url', $prUrl))
            ->whereNull('resolution_reply_github_id')
            ->orderBy('created_at')
            ->get();

        $hydrated = [];
        foreach ($comments as $comment) {
            if (isset($resolvedCommentIds[(int) $comment->github_comment_id])) {
                continue;
            }

            $hydrated[] = [
                'comment_id' => (int) $comment->github_comment_id,
                'file' => (string) $comment->file_path,
                'line' => (int) $comment->line_number,
                'severity' => (string) $comment->severity,
                'category' => (string) $comment->category,
                'body' => (string) $comment->body,
                'file_changed_in_this_push' => isset($changedSet[(string) $comment->file_path]),
            ];
        }

        if (count($hydrated) > $cap) {
            $hydrated = array_slice($hydrated, -1 * $cap);
        }

        return $hydrated;
    }
}
