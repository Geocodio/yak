<?php

namespace App\Console\Commands;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\PullRequestFacts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fills the review queue's pull request facts (draft flag, head commit, opened
 * time, mergeable state and each reviewer's verdict) for every open pull
 * request, replacing whatever the rows held before.
 */
#[Signature('yak:refresh-pull-request-facts')]
#[Description('Backfill review queue facts for open thread pull requests')]
class RefreshPullRequestFactsCommand extends Command
{
    public function handle(AppService $github, PullRequestFacts $facts): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $refreshed = 0;
        $failed = 0;

        ThreadRepository::query()
            ->where('pull_request_state', 'open')
            ->whereNotNull('pull_request_number')
            ->chunkById(100, function ($rows) use ($github, $facts, $installationId, &$refreshed, &$failed): void {
                foreach ($rows as $row) {
                    $fullName = Repository::githubNameFor($row->repo);
                    $number = (int) $row->pull_request_number;

                    try {
                        $pullRequest = $github->getPullRequestOrFail($installationId, $fullName, $number);

                        if ($pullRequest === null) {
                            $this->line("{$fullName}#{$number}: not found");

                            continue;
                        }

                        $reviews = $github->listPullRequestReviews($installationId, $fullName, $number);
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->components->warn("Failed {$fullName}#{$number}: {$exception->getMessage()}");

                        continue;
                    }

                    $facts->recordPullRequest($pullRequest, $fullName);

                    ThreadRepository::query()->where('repo', $row->repo)->where('pull_request_number', $number)->update([
                        'pull_request_mergeable_state' => (string) ($pullRequest['mergeable_state'] ?? 'unknown'),
                        'pull_request_reviews' => null,
                    ]);

                    foreach ($reviews as $review) {
                        $facts->recordReview($review, 'submitted', $fullName, $number);
                    }

                    $refreshed++;
                    $this->line("{$fullName}#{$number}: refreshed");
                }
            });

        $this->components->info("Refreshed {$refreshed}, failed {$failed}.");

        return self::SUCCESS;
    }
}
