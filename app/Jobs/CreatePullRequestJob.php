<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskMode;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\PullRequestBodyUpdater;
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

        $parts[] = "**Repository:** {$this->task->repo}";
        $parts[] = "**Attempts:** {$this->task->attempts}";

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '';
        $parts[] = $this->task->result_summary ?? '_No summary available._';

        $screenshots = array_filter($signedUrls, fn (array $a): bool => $a['type'] === 'screenshot');
        if (count($screenshots) > 0) {
            $parts[] = '';
            $parts[] = '### Screenshots';
            foreach ($screenshots as $screenshot) {
                $parts[] = "![{$screenshot['filename']}]({$screenshot['url']})";
                $parts[] = '';
            }
        }

        // Prefer the rendered reviewer cut (Remotion output) over the raw webm.
        // The cut is a polished mp4 with title cards, callouts, etc. — reviewers
        // should see that. Fall back to raw video artifacts only if rendering
        // didn't produce a cut (e.g. no storyboard, or RenderVideoJob failed).
        $videoCut = $this->task->artifacts()->reviewerCut()->latest('id')->first();
        if ($videoCut !== null) {
            $thumbnail = $this->task->artifacts()->reviewerThumbnail()->latest('id')->first();
            $parts[] = '';
            $parts[] = '### Video walkthrough';
            $parts[] = PullRequestBodyUpdater::videoMarkdown(
                videoUrl: $videoCut->signedUrl(),
                filename: $videoCut->filename,
                thumbnailUrl: $thumbnail?->signedUrl(),
            );
        } else {
            $videos = array_filter($signedUrls, fn (array $a): bool => $a['type'] === 'video');
            if (count($videos) > 0) {
                $parts[] = '';
                $parts[] = '### Video walkthrough';
                foreach ($videos as $video) {
                    $parts[] = "- [{$video['filename']}]({$video['url']})";
                }
            }
        }

        return implode("\n", $parts);
    }
}
