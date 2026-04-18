<?php

namespace App\Services;

/**
 * Edits an existing PR's body on GitHub to append (or create) a Director's
 * Cut link inside the "Video walkthrough" section. Used by RenderVideoJob
 * once the director-tier MP4 finishes rendering — the original PR was
 * already created with only the reviewer cut, so we patch in the extended
 * cut after the fact instead of blocking PR creation on the long render.
 */
class PullRequestBodyUpdater
{
    public function __construct(public GitHubAppService $github) {}

    /**
     * Append a "Watch Director's Cut" link to the PR body. If the PR body
     * already contains a "### Video walkthrough" section, the link is
     * inserted into that section; otherwise a new section is created at
     * the end of the body.
     */
    public function appendDirectorCut(string $repoFullName, int $prNumber, string $directorCutUrl): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
        $body = (string) ($pr['body'] ?? '');

        $line = "[▶ Watch Director's Cut]({$directorCutUrl})";

        if (str_contains($body, "Director's Cut")) {
            // Already linked — don't duplicate on retries.
            return;
        }

        if (str_contains($body, '### Video walkthrough')) {
            // Insert the new link at the end of the Video walkthrough
            // section (delimited by the next heading or end-of-body),
            // preserving whatever reviewer-cut link is already there.
            $updated = preg_replace(
                '/(### Video walkthrough\n(?:.|\n)*?)(\n(?:#{1,6}|---)|$)/',
                "$1\n- {$line}\n$2",
                $body,
                1,
                $count,
            );

            if ($count > 0 && is_string($updated)) {
                $body = $updated;
            } else {
                $body .= "\n- {$line}\n";
            }
        } else {
            $body .= "\n\n### Video walkthrough\n\n- {$line}\n";
        }

        $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $body]);
    }
}
