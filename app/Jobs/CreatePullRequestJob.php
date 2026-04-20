<?php

namespace App\Jobs;

use App\Enums\TaskMode;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
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
        $signedUrls = $this->generateSignedUrls();

        $prResponse = $gitHub->createPullRequest($installationId, $repository->slug, [
            'title' => $this->buildPrTitle(),
            'head' => $this->task->branch_name,
            'base' => $repository->default_branch,
            'body' => $this->buildPrBody($signedUrls),
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
