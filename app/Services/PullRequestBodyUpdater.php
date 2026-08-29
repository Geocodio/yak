<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;

/**
 * Edits an existing PR's body on GitHub so the walkthrough section reflects
 * the current state of the render. A PR opens while the render is still in
 * flight, so its body starts as a placeholder; this updater swaps the whole
 * marked block for the finished preview, or for an explicit notice when the
 * render fails for good.
 */
class PullRequestBodyUpdater
{
    public function __construct(public GitHubAppService $github) {}

    /**
     * Yak owns everything between the walkthrough markers and rewrites it
     * wholesale (spec §8). A GIF, a link and a chapter line do not fit the
     * single-line regex the v2 body used, and a partial edit would leave
     * a mixture of the two shapes behind.
     */
    public function setWalkthroughSection(string $repoFullName, int $prNumber, string $section): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
        $body = (string) ($pr['body'] ?? '');

        $updated = WalkthroughPrSection::replaceIn($body, $section);

        if ($updated === $body) {
            return;
        }

        $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $updated]);
    }

    /**
     * Publish the finished walkthrough. `$filename` is retained for the
     * legacy `RenderVideoJob` caller; the rendered section labels the link
     * by duration rather than by file name.
     *
     * @param  array<int, array{title: string, startSeconds: float, url: string}>  $chapters
     */
    public function setWalkthrough(
        string $repoFullName,
        int $prNumber,
        string $walkthroughUrl,
        string $filename = 'walkthrough.mp4',
        ?string $thumbnailUrl = null,
        ?string $gifUrl = null,
        float $durationSeconds = 0.0,
        array $chapters = [],
    ): void {
        $this->setWalkthroughSection($repoFullName, $prNumber, WalkthroughPrSection::ready(
            videoUrl: $walkthroughUrl,
            gifUrl: $gifUrl,
            thumbnailUrl: $thumbnailUrl,
            durationSeconds: $durationSeconds,
            chapters: $chapters,
        ));
    }

    /**
     * Replace the walkthrough section with an explicit unavailable notice so
     * a PR never keeps a "rendering" placeholder after the render has failed
     * for good.
     */
    public function setWalkthroughUnavailable(string $repoFullName, int $prNumber, string $reason): void
    {
        $this->setWalkthroughSection($repoFullName, $prNumber, WalkthroughPrSection::unavailable($reason));
    }

    /**
     * Clickable-thumbnail markdown when a poster image exists, else a
     * plain link.
     *
     * @deprecated Superseded by WalkthroughPrSection::ready(); kept for
     *             callers still emitting the v2 single-line shape.
     */
    public static function videoMarkdown(string $videoUrl, string $filename, ?string $thumbnailUrl): string
    {
        if ($thumbnailUrl === null || $thumbnailUrl === '') {
            return "- [{$filename}]({$videoUrl})";
        }

        return "[![Watch {$filename}]({$thumbnailUrl})]({$videoUrl})";
    }
}
