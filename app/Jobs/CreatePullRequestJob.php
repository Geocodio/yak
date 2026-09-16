<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskMode;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\PullRequestBodySections;
use App\Services\PullRequestBodyUpdater;
use App\Services\PullRequestTitle;
use App\Services\TaskLogger;
use App\Services\WalkthroughPrSection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreatePullRequestJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly bool $isLargeChange = false,
    ) {
        $this->onQueue('default');
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('yak')->error(self::class . ' failed', [
            'task_id' => $this->task->id,
            'error' => $e?->getMessage() ?? 'Job failed without exception',
            'exception_class' => $e !== null ? get_class($e) : null,
        ]);
    }

    public function handle(GitHubAppService $gitHub): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $installationId = (int) config('yak.channels.github.installation_id');

        // A follow-up pushes to an existing branch, so a PR already exists.
        // Detect it and post a summary comment instead of POSTing a new PR
        // (which GitHub rejects with 422).
        $existing = $gitHub->findOpenPullRequestForBranch($installationId, $repository->github_full_name, (string) $this->task->branch_name);

        if ($existing !== null) {
            if (! isset($existing['number'], $existing['html_url'])) {
                throw new \RuntimeException('GitHub returned an existing PR without expected fields.');
            }

            $this->task->update([
                'pr_url' => $existing['html_url'],
                'pr_number' => $existing['number'],
            ]);

            $summary = $this->task->result_summary ?? '_No summary available._';
            $gitHub->commentOnPullRequest(
                $installationId,
                $repository->github_full_name,
                (int) $existing['number'],
                mb_convert_encoding("Yak pushed changes addressing your feedback:\n\n{$summary}", 'UTF-8', 'UTF-8'),
            );

            $this->refreshOwnedSections($repository->github_full_name, (int) $existing['number']);

            return;
        }

        $signedUrls = $this->generateSignedUrls();

        $title = mb_convert_encoding($this->buildPrTitle(), 'UTF-8', 'UTF-8');
        $body = mb_convert_encoding($this->buildPrBody($signedUrls), 'UTF-8', 'UTF-8');

        $prResponse = $gitHub->createPullRequest($installationId, $repository->github_full_name, [
            'title' => $title,
            'head' => $this->task->branch_name,
            'base' => $repository->default_branch,
            'body' => $body,
        ]);

        if (! isset($prResponse['number'], $prResponse['html_url'])) {
            $error = $prResponse['message'] ?? json_encode($prResponse);
            throw new \RuntimeException("GitHub PR creation failed: {$error}");
        }

        $prNumber = $prResponse['number'];
        $prUrl = $prResponse['html_url'];

        $labels = ['yak'];
        if ($this->isLargeChange) {
            $labels[] = 'yak-large-change';
        }

        $gitHub->addLabels($installationId, $repository->github_full_name, $prNumber, $labels);

        $this->task->update(['pr_url' => $prUrl, 'pr_number' => $prNumber]);
    }

    private function buildPrTitle(): string
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;
        $prefix = match ($mode) {
            TaskMode::Research => 'Yak Research',
            TaskMode::Setup => 'Yak Setup',
            default => 'Yak Fix',
        };

        $generated = (new PullRequestTitle)->generate($this->task);
        if ($generated !== null) {
            return "{$prefix}: {$generated}";
        }

        $description = $this->task->description;

        // Character-aware truncation. `substr` slicing bytes mid-sequence
        // produces orphan continuation bytes that Guzzle refuses to encode.
        if (mb_strlen($description) > 60) {
            $description = mb_substr($description, 0, 57) . '...';
        }

        return "{$prefix}: {$description}";
    }

    /**
     * @return array<int, array{filename: string, url: string, type: string}>
     */
    private function generateSignedUrls(): array
    {
        $artifacts = Artifact::where('yak_task_id', $this->task->id)->get();
        $signedUrls = [];

        foreach ($artifacts as $artifact) {
            $signedUrls[] = [
                'filename' => $artifact->filename,
                'url' => $artifact->signedUrl(),
                'type' => $artifact->type,
            ];
        }

        return $signedUrls;
    }

    /**
     * @param  array<int, array{filename: string, url: string, type: string}>  $signedUrls
     */
    private function buildPrBody(array $signedUrls): string
    {
        $taskUrl = $this->task->external_url ?? '';
        $parts = [
            "**Source:** {$this->task->source}",
        ];

        if ($taskUrl !== '') {
            $parts[] = "**Task:** [{$this->task->external_id}]({$taskUrl})";
        }

        $parts[] = "**Yak task:** [#{$this->task->id}]({$this->yakTaskUrl()})";
        $parts[] = "**Repository:** {$this->task->repo}";
        $parts[] = "**Attempts:** {$this->task->attempts}";

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = PullRequestBodySections::wrap(
            PullRequestBodySections::DESCRIPTION,
            $this->task->result_summary ?? '_No summary available._',
        );

        $screenshots = $this->screenshotsSection();

        if ($screenshots !== null) {
            $parts[] = '';
            $parts[] = $screenshots;
        }

        $walkthrough = $this->walkthroughSection();

        if ($walkthrough !== '') {
            $parts[] = '';
            $parts[] = $walkthrough;
        }

        return implode("\n", $parts);
    }

    /**
     * Whether a render is on its way. The v3 pipeline needs both a script
     * and a manifest artifact (RenderWalkthroughJob's own precondition);
     * the legacy v2 pipeline renders from raw footage alone.
     */
    private function hasRenderableCapture(): bool
    {
        if ($this->task->artifacts()->rawFootage()->exists()) {
            return true;
        }

        return $this->task->artifacts()->role('script')->exists()
            && $this->task->artifacts()->role('manifest')->exists();
    }

    /**
     * Deep link back to the task's page on this Yak install, so a reviewer
     * can reach the run log, the cost, and the follow-up box from the PR.
     */
    private function yakTaskUrl(): string
    {
        return route('tasks.show', $this->task);
    }

    /**
     * The owned screenshots block for this task, or null when the task
     * captured none. A follow-up that captured new screenshots replaces
     * the block wholesale; one that captured nothing leaves it alone.
     */
    private function screenshotsSection(): ?string
    {
        $screenshotArtifacts = $this->task->artifacts()->role('screenshot')->orderBy('id')->get();

        if ($screenshotArtifacts->isEmpty()) {
            return null;
        }

        $rendered = WalkthroughPrSection::screenshots(
            $screenshotArtifacts->map(fn (Artifact $artifact): array => [
                'caption' => $artifact->caption,
                'url' => $artifact->publicUrl() ?? $artifact->signedUrl(),
            ])->all(),
        );

        return PullRequestBodySections::wrap(PullRequestBodySections::SCREENSHOTS, "### Screenshots\n\n" . rtrim($rendered));
    }

    /**
     * After a follow-up lands, swap the description and screenshots blocks
     * so the body describes the PR as it now stands. The comment above is
     * the changelog; the body is the current state. Runs on green CI only,
     * so a follow-up that failed CI never rewrites the description.
     */
    private function refreshOwnedSections(string $repoFullName, int $prNumber): void
    {
        $sections = [];

        if ($this->task->pr_body_update !== null && trim((string) $this->task->pr_body_update) !== '') {
            $sections[PullRequestBodySections::DESCRIPTION] = PullRequestBodySections::wrap(
                PullRequestBodySections::DESCRIPTION,
                (string) $this->task->pr_body_update,
            );
        }

        $screenshots = $this->screenshotsSection();

        if ($screenshots !== null) {
            $sections[PullRequestBodySections::SCREENSHOTS] = $screenshots;
        }

        if ($sections === []) {
            return;
        }

        // One place to sanitise every owned section before it goes to
        // GitHub, rather than converting the description inline and
        // leaving the screenshots block untouched.
        $sections = array_map(
            fn (string $section): string => mb_convert_encoding($section, 'UTF-8', 'UTF-8'),
            $sections,
        );

        $updater = app(PullRequestBodyUpdater::class);

        try {
            $applied = $updater->setSections($repoFullName, $prNumber, $sections);
            $skipped = array_values(array_diff(array_keys($sections), $applied));

            // A follow-up that captured screenshots for a PR opened without
            // any has no screenshots markers to swap, so setSections skips
            // it. Insert the block right after the description instead;
            // insertSectionAfter leaves a legacy PR with no description
            // markers untouched.
            if (isset($sections[PullRequestBodySections::SCREENSHOTS]) && in_array(PullRequestBodySections::SCREENSHOTS, $skipped, true)) {
                $inserted = $updater->insertSectionAfter(
                    $repoFullName,
                    $prNumber,
                    PullRequestBodySections::DESCRIPTION,
                    PullRequestBodySections::SCREENSHOTS,
                    $sections[PullRequestBodySections::SCREENSHOTS],
                );

                if ($inserted) {
                    $applied[] = PullRequestBodySections::SCREENSHOTS;
                    $skipped = array_values(array_diff($skipped, [PullRequestBodySections::SCREENSHOTS]));
                }
            }

            TaskLogger::info($this->task, 'PR body sections refreshed', ['applied' => $applied, 'skipped' => $skipped]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('CreatePullRequestJob: failed to refresh PR body sections', [
                'task_id' => $this->task->id,
                'sections' => array_keys($sections),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The PR opens on green CI, usually before the render finishes, so the
     * section starts as a placeholder the render replaces (spec §8).
     *
     * A task that captured nothing — a backend-only change, or a run that
     * skipped visual capture — never dispatches a render, so a placeholder
     * would sit at "Rendering…" forever. The section is omitted unless a
     * render is actually coming.
     */
    private function walkthroughSection(): string
    {
        $cut = $this->task->artifacts()->cut()->latest('id')->first();

        if ($cut === null) {
            return $this->hasRenderableCapture() ? WalkthroughPrSection::pending() : '';
        }

        $preview = $this->task->artifacts()->preview()->latest('id')->first();
        $thumbnail = $this->task->artifacts()->thumbnail()->latest('id')->first();

        return WalkthroughPrSection::ready(
            videoUrl: $cut->signedUrl(),
            gifUrl: $preview?->publicUrl() ?? $preview?->signedUrl(),
            thumbnailUrl: $thumbnail?->publicUrl() ?? $thumbnail?->signedUrl(),
            durationSeconds: (float) (VideoMetric::where('yak_task_id', $this->task->id)
                ->where('status', VideoMetric::STATUS_RENDERED)
                ->latest('id')->value('duration_seconds') ?? 0.0),
            chapters: WalkthroughPrSection::chaptersForTask($this->task),
        );
    }
}
