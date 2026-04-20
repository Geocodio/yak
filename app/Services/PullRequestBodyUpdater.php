<?php

namespace App\Services;

/**
 * Edits an existing PR's body on GitHub to swap the video walkthrough link
 * for the rendered reviewer-cut mp4 once the Remotion pipeline finishes.
 * PR creation may happen while the render is still in flight, so the
 * initial body links the raw webm as a fallback; this updater upgrades
 * that link to the polished cut after the render lands.
 *
 * Director cuts are intentionally not published to the PR — they're a
 * manually triggered, viewer-facing artifact on the task detail page.
 */
class PullRequestBodyUpdater
{
    public function __construct(public GitHubAppService $github) {}

    public function setReviewerCut(
        string $repoFullName,
        int $prNumber,
        string $reviewerCutUrl,
        string $filename = 'reviewer-cut.mp4',
    ): void {
        $installationId = (int) config('yak.channels.github.installation_id');

        $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
        $body = (string) ($pr['body'] ?? '');

        // Idempotent: if the reviewer cut filename already appears linked,
        // don't bother re-patching (signed URLs rotate, so we match on the
        // stable filename rather than the URL).
        if (preg_match('/\[' . preg_quote($filename, '/') . '\]\(/', $body) === 1) {
            return;
        }

        $newLine = "- [{$filename}]({$reviewerCutUrl})";

        if (str_contains($body, '### Video walkthrough')) {
            // Replace whatever single link lives under the heading (the raw
            // webm fallback dropped in by CreatePullRequestJob).
            $replaced = preg_replace(
                '/(### Video walkthrough\s*\n\s*\n)- \[[^\]]+\]\([^)]+\)/',
                "$1{$newLine}",
                $body,
                1,
                $count,
            );

            if (is_string($replaced) && $count > 0) {
                $body = $replaced;
            } else {
                // Section exists but shape is unexpected; append the link.
                $body .= "\n{$newLine}\n";
            }
        } else {
            $body .= "\n\n### Video walkthrough\n\n{$newLine}\n";
        }

        $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $body]);
    }
}
