<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;

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
        ?string $thumbnailUrl = null,
    ): void {
        $installationId = (int) config('yak.channels.github.installation_id');

        $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
        $body = (string) ($pr['body'] ?? '');

        $markdown = self::videoMarkdown($reviewerCutUrl, $filename, $thumbnailUrl);

        // Idempotent: if the body already has this filename in the same
        // shape we'd produce (image-embed when a thumbnail is available,
        // plain link otherwise), leave it alone. Signed URLs rotate, so
        // the test has to be shape-based rather than URL-based.
        $alreadyEmbedded = $thumbnailUrl !== null
            ? str_contains($body, "![Watch {$filename}](")
            : preg_match('/\[' . preg_quote($filename, '/') . '\]\(/', $body) === 1 && ! str_contains($body, "![Watch {$filename}](");

        if ($alreadyEmbedded) {
            return;
        }

        if (str_contains($body, '### Video walkthrough')) {
            // Replace exactly the one link-like line that sits directly
            // under the heading. Matches either the plain-text form
            // (`- [filename](url)`) emitted by CreatePullRequestJob or
            // the image-embed form produced by this updater on an earlier
            // pass. Keeps the regex local so nothing downstream of the
            // section (### Files changed, `---`, warning callout) gets
            // swallowed when the walkthrough is the last heading.
            $linkLine = '(?:- \[[^\]]+\]\([^)]+\)|\[!\[[^\]]*\]\([^)]+\)\]\([^)]+\))';
            $replaced = preg_replace(
                "/(### Video walkthrough\s*\n\s*\n){$linkLine}/",
                "$1{$markdown}",
                $body,
                1,
                $count,
            );

            if (is_string($replaced) && $count > 0) {
                $body = $replaced;
            } else {
                // Section exists but shape is unexpected; append the link.
                $body .= "\n{$markdown}\n";
            }
        } else {
            $body .= "\n\n### Video walkthrough\n\n{$markdown}\n";
        }

        $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $body]);
    }

    /**
     * Clickable-thumbnail markdown when a poster image exists, else a
     * plain link. GitHub doesn't embed video inline but will render the
     * thumbnail and make it clickable, which is the closest thing to a
     * preview we can ship in a PR body.
     */
    public static function videoMarkdown(string $videoUrl, string $filename, ?string $thumbnailUrl): string
    {
        if ($thumbnailUrl === null || $thumbnailUrl === '') {
            return "- [{$filename}]({$videoUrl})";
        }

        return "[![Watch {$filename}]({$thumbnailUrl})]({$videoUrl})";
    }
}
