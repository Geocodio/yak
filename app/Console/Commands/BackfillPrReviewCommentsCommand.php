<?php

namespace App\Console\Commands;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-shot backfill for reviews posted before listReviewComments existed:
 * the create-review response never contained the inline comments, so none
 * were persisted (issue #7). Delete this command once it has run in
 * production — tracked in the follow-up issue.
 */
#[Signature('yak:backfill-pr-review-comments {--dry-run : Report what would be stored without writing}')]
#[Description('Backfill pr_review_comments for reviews whose inline comments were never persisted')]
class BackfillPrReviewCommentsCommand extends Command
{
    public function handle(GitHubAppService $github): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            $this->components->error('No GitHub installation configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $reviews = PrReview::query()
            ->whereNotNull('github_review_id')
            ->whereDoesntHave('comments')
            ->get();

        if ($reviews->isEmpty()) {
            $this->components->info('No reviews need backfilling.');

            return self::SUCCESS;
        }

        $stored = 0;
        $failed = 0;

        foreach ($reviews as $review) {
            $repository = Repository::query()->where('slug', $review->repo)->first();

            if ($repository === null) {
                $failed++;
                $this->components->warn("No repository found for slug {$review->repo} (review #{$review->id})");

                continue;
            }

            try {
                $comments = $github->listReviewComments(
                    $installationId,
                    $repository->github_full_name,
                    (int) $review->pr_number,
                    (int) $review->github_review_id,
                );
            } catch (\Throwable $e) {
                $failed++;
                $this->components->warn("Failed to fetch comments for review #{$review->id} (PR {$review->repo}#{$review->pr_number}): {$e->getMessage()}");

                continue;
            }

            foreach ($comments as $comment) {
                $githubCommentId = (int) ($comment['id'] ?? 0);

                if ($githubCommentId === 0 || PrReviewComment::query()->where('github_comment_id', $githubCommentId)->exists()) {
                    continue;
                }

                $body = (string) ($comment['body'] ?? '');
                [$category, $severity] = $this->parseFindingHeader($body);

                if ($dryRun) {
                    $this->line("Would store comment {$githubCommentId} ({$category} · {$severity}) for review #{$review->id}");

                    continue;
                }

                PrReviewComment::create([
                    'pr_review_id' => $review->id,
                    'github_comment_id' => $githubCommentId,
                    'file_path' => (string) ($comment['path'] ?? ''),
                    'line_number' => (int) ($comment['line'] ?? $comment['original_line'] ?? 0),
                    'body' => $body,
                    'category' => $category,
                    'severity' => $severity,
                    'is_suggestion' => str_contains($body, '```suggestion'),
                ]);

                $stored++;
            }
        }

        $this->components->info("Backfill complete: {$stored} comments stored, {$failed} reviews skipped, {$reviews->count()} reviews scanned.");

        return self::SUCCESS;
    }

    /**
     * Recover category and severity from the "**[Category · SEVERITY]**"
     * header that RunYakReviewJob prefixes onto every posted comment.
     * NITPICK is the posted label for consider-severity findings.
     *
     * @return array{0: string, 1: string}
     */
    private function parseFindingHeader(string $body): array
    {
        if (preg_match('/^\*\*\[(.+?) · ([A-Z_]+)\]\*\*/u', $body, $matches) !== 1) {
            return ['Uncategorized', 'consider'];
        }

        $severity = $matches[2] === 'NITPICK' ? 'consider' : strtolower($matches[2]);

        if (! in_array($severity, ['must_fix', 'should_fix', 'consider'], true)) {
            $severity = 'consider';
        }

        return [$matches[1], $severity];
    }
}
