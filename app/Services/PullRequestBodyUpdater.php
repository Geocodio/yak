<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;
use Illuminate\Support\Facades\Cache;

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
        $this->withBodyLock($repoFullName, $prNumber, function () use ($repoFullName, $prNumber, $section): void {
            $installationId = (int) config('yak.channels.github.installation_id');

            $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
            $body = (string) ($pr['body'] ?? '');

            $updated = WalkthroughPrSection::replaceIn($body, $section);

            if ($updated === $body) {
                return;
            }

            $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $updated]);
        });
    }

    /**
     * Replace several of Yak's marked sections in one read and one write.
     * A section whose markers are missing from the body is skipped, so a
     * PR opened before the markers existed is never rewritten.
     *
     * @param  array<string, string>  $sections  section name => already-wrapped block
     * @return array<int, string> the names of the sections actually replaced
     */
    public function setSections(string $repoFullName, int $prNumber, array $sections): array
    {
        return $this->withBodyLock($repoFullName, $prNumber, function () use ($repoFullName, $prNumber, $sections): array {
            $installationId = (int) config('yak.channels.github.installation_id');

            $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
            $body = (string) ($pr['body'] ?? '');
            $updated = $body;
            $applied = [];

            foreach ($sections as $name => $section) {
                $next = PullRequestBodySections::replace($updated, $name, $section);

                if ($next !== $updated) {
                    $applied[] = $name;
                }

                $updated = $next;
            }

            if ($updated === $body) {
                return [];
            }

            $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $updated]);

            return $applied;
        });
    }

    /**
     * Insert a section that has no markers of its own yet, right before
     * another owned section's start marker. Used when a follow-up captures
     * screenshots for a PR that was originally opened without any: the
     * screenshots markers don't exist, so `setSections` would skip it, but
     * the PR does have a description block to anchor the insert to, and the
     * body order keeps the screenshots ahead of the description.
     *
     * A legacy PR with no `$beforeName` markers, and a PR that already has
     * `$name` markers, are both left untouched.
     */
    public function insertSectionBefore(string $repoFullName, int $prNumber, string $beforeName, string $name, string $section): bool
    {
        return $this->withBodyLock($repoFullName, $prNumber, function () use ($repoFullName, $prNumber, $beforeName, $name, $section): bool {
            $installationId = (int) config('yak.channels.github.installation_id');

            $pr = $this->github->getPullRequest($installationId, $repoFullName, $prNumber);
            $body = (string) ($pr['body'] ?? '');

            if (PullRequestBodySections::has($body, $name) || ! PullRequestBodySections::has($body, $beforeName)) {
                return false;
            }

            $marker = PullRequestBodySections::startMarker($beforeName);
            $insertAt = strpos($body, $marker);

            if ($insertAt === false) {
                return false;
            }

            $updated = substr($body, 0, $insertAt) . "{$section}\n\n" . substr($body, $insertAt);

            $this->github->updatePullRequest($installationId, $repoFullName, $prNumber, ['body' => $updated]);

            return true;
        });
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

    /**
     * Serializes every read-replace-write against the same PR body behind
     * one lock key, so `RenderWalkthroughJob` and `CreatePullRequestJob`
     * (or two calls from either) never race a GET/PATCH pair against each
     * other and drop one side's edit.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withBodyLock(string $repoFullName, int $prNumber, \Closure $callback): mixed
    {
        return Cache::lock("pr-body:{$repoFullName}#{$prNumber}", 10)->block(5, $callback);
    }
}
