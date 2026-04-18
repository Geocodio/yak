<?php

namespace App\Jobs;

use App\Enums\TaskMode;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
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
        $signedUrls = $this->generateSignedUrls();
        $changedFiles = $this->fetchChangedFiles($gitHub, $installationId, $repository);

        $prResponse = $gitHub->createPullRequest($installationId, $repository->slug, [
            'title' => $this->buildPrTitle(),
            'head' => $this->task->branch_name,
            'base' => $repository->default_branch,
            'body' => $this->buildPrBody($signedUrls, $changedFiles),
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

        $gitHub->addLabels($installationId, $repository->slug, $prNumber, $labels);

        $this->task->update(['pr_url' => $prUrl]);
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

        if (strlen($description) > 60) {
            $description = substr($description, 0, 57) . '...';
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
     * @param  array<int, string>  $changedFiles
     */
    private function buildPrBody(array $signedUrls, array $changedFiles): string
    {
        $taskUrl = $this->task->external_url ?? '';
        $parts = [
            '## Yak Automated PR',
            '',
            "**Source:** {$this->task->source}",
        ];

        if ($taskUrl !== '') {
            $parts[] = "**Task:** [{$this->task->external_id}]({$taskUrl})";
        }

        $parts[] = "**Repository:** {$this->task->repo}";
        $parts[] = "**Attempts:** {$this->task->attempts}";

        $parts[] = '';
        $parts[] = '### What changed';
        $parts[] = $this->task->result_summary ?? 'No summary available';

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
            $parts[] = '';
            $parts[] = '### Video walkthrough';
            $parts[] = "- [{$videoCut->filename}]({$videoCut->signedUrl()})";
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

        if (count($changedFiles) > 0) {
            $parts[] = '';
            $parts[] = '### Files changed';
            foreach ($changedFiles as $file) {
                $parts[] = "- `{$file}`";
            }
        }

        $parts[] = '';
        $parts[] = '---';
        $parts[] = '> **Warning:** This PR was generated by Yak. Review before merging.';

        return implode("\n", $parts);
    }

    /**
     * @return array<int, string>
     */
    private function fetchChangedFiles(GitHubAppService $gitHub, int $installationId, Repository $repository): array
    {
        if ($this->task->branch_name === null || $installationId === 0) {
            return [];
        }

        try {
            $compare = $gitHub->compareBranches(
                $installationId,
                $repository->slug,
                $repository->default_branch,
                $this->task->branch_name,
            );

            return $compare['files'];
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch changed files via GitHub API', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
