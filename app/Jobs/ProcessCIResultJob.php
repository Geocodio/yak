<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class ProcessCIResultJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly bool $passed,
        public readonly ?string $output = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if ($this->passed) {
            $this->handleGreenPath();
        } elseif ($this->task->attempts < (int) config('yak.max_attempts')) {
            $this->handleRetry();
        } else {
            $this->handleFinalFailure();
        }
    }

    private function handleGreenPath(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $artifacts = $this->collectArtifacts($repository);
        $signedUrls = $this->generateSignedUrls($artifacts);

        $loc = $this->countLinesOfCode($repository);
        $isLargeChange = $loc > (int) config('yak.large_change_threshold');

        $prUrl = $this->createPullRequest($repository, $signedUrls, $isLargeChange);

        $this->postToSource("PR created: {$prUrl}");

        if ($this->task->source === 'linear') {
            $this->moveLinearToInReview();
        }

        $this->task->update([
            'status' => TaskStatus::Success,
            'pr_url' => $prUrl,
            'completed_at' => now(),
        ]);

        $this->cleanupBranch($repository);
    }

    private function handleRetry(): void
    {
        $this->postToSource('CI failed, retrying');

        $this->task->update([
            'status' => TaskStatus::Retrying,
            'attempts' => $this->task->attempts + 1,
        ]);

        RetryYakJob::dispatch($this->task, $this->output);
    }

    private function handleFinalFailure(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $failureSummary = $this->output ?? 'CI failed after maximum attempts';
        $this->postToSource("CI failed: {$failureSummary}");

        $this->task->update([
            'status' => TaskStatus::Failed,
            'completed_at' => now(),
            'error_log' => $failureSummary,
        ]);

        $this->cleanupBranch($repository);
    }

    /**
     * @return array<int, Artifact>
     */
    private function collectArtifacts(Repository $repository): array
    {
        $artifactsPath = $repository->path.'/.yak-artifacts';

        if (! File::isDirectory($artifactsPath)) {
            return [];
        }

        $files = File::files($artifactsPath);
        $artifacts = [];

        foreach ($files as $file) {
            $artifacts[] = Artifact::create([
                'yak_task_id' => $this->task->id,
                'type' => $this->detectArtifactType($file->getExtension()),
                'filename' => $file->getFilename(),
                'disk_path' => $file->getPathname(),
                'size_bytes' => $file->getSize(),
            ]);
        }

        return $artifacts;
    }

    private function detectArtifactType(string $extension): string
    {
        return match (strtolower($extension)) {
            'png', 'jpg', 'jpeg', 'gif', 'webp' => 'screenshot',
            'mp4', 'webm' => 'video',
            'html' => 'research',
            default => 'file',
        };
    }

    /**
     * @param  array<int, Artifact>  $artifacts
     * @return array<int, array{filename: string, url: string}>
     */
    private function generateSignedUrls(array $artifacts): array
    {
        $signedUrls = [];

        foreach ($artifacts as $artifact) {
            $expires = now()->addDays(7)->timestamp;
            $payload = "{$artifact->id}:{$expires}";
            $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

            $signedUrls[] = [
                'filename' => $artifact->filename,
                'url' => url("/artifacts/{$artifact->id}?expires={$expires}&signature={$signature}"),
            ];
        }

        return $signedUrls;
    }

    private function countLinesOfCode(Repository $repository): int
    {
        $result = Process::path($repository->path)
            ->run("git diff --stat {$repository->default_branch}...{$this->task->branch_name}");

        $output = trim($result->output());
        $lines = explode("\n", $output);
        $summary = end($lines);

        $added = 0;
        $removed = 0;

        if (preg_match('/(\d+)\s+insertions?\(\+\)/', $summary, $insertions)) {
            $added = (int) $insertions[1];
        }

        if (preg_match('/(\d+)\s+deletions?\(-\)/', $summary, $deletions)) {
            $removed = (int) $deletions[1];
        }

        return $added + $removed;
    }

    /**
     * @param  array<int, array{filename: string, url: string}>  $signedUrls
     */
    private function createPullRequest(Repository $repository, array $signedUrls, bool $isLargeChange): string
    {
        $token = $this->getGitHubToken();
        $body = $this->buildPrBody($signedUrls);
        $title = $this->buildPrTitle();

        $response = Http::withToken($token)
            ->post("https://api.github.com/repos/{$repository->slug}/pulls", [
                'title' => $title,
                'head' => $this->task->branch_name,
                'base' => $repository->default_branch,
                'body' => $body,
            ]);

        /** @var int $prNumber */
        $prNumber = $response->json('number');
        /** @var string $prUrl */
        $prUrl = $response->json('html_url');

        $labels = ['yak'];
        if ($isLargeChange) {
            $labels[] = 'yak-large-change';
        }

        Http::withToken($token)
            ->post("https://api.github.com/repos/{$repository->slug}/issues/{$prNumber}/labels", [
                'labels' => $labels,
            ]);

        return $prUrl;
    }

    private function buildPrTitle(): string
    {
        $description = $this->task->description;

        if (strlen($description) > 60) {
            $description = substr($description, 0, 57).'...';
        }

        return "[Yak] {$description}";
    }

    /**
     * @param  array<int, array{filename: string, url: string}>  $signedUrls
     */
    private function buildPrBody(array $signedUrls): string
    {
        $parts = [
            '## Yak Automated PR',
            '',
            "**Source:** {$this->task->source}",
            "**Repository:** {$this->task->repo}",
            '',
            '### Summary',
            $this->task->result_summary ?? 'No summary available',
        ];

        if (count($signedUrls) > 0) {
            $parts[] = '';
            $parts[] = '### Artifacts';
            foreach ($signedUrls as $artifact) {
                $parts[] = "- [{$artifact['filename']}]({$artifact['url']})";
            }
        }

        return implode("\n", $parts);
    }

    private function getGitHubToken(): string
    {
        return (string) config('yak.channels.github.private_key');
    }

    private function postToSource(string $message): void
    {
        match ($this->task->source) {
            'slack' => $this->postToSlack($message),
            'linear' => $this->postToLinear($message),
            default => null,
        };
    }

    private function postToSlack(string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $this->task->slack_channel) {
            return;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $this->task->slack_channel,
                'thread_ts' => $this->task->slack_thread_ts,
                'text' => $message,
            ]);
    }

    private function postToLinear(string $message): void
    {
        $apiKey = (string) config('yak.channels.linear.api_key');

        if ($apiKey === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post('https://api.linear.app/graphql', [
                'query' => 'mutation($issueId: String!, $body: String!) { commentCreate(input: { issueId: $issueId, body: $body }) { success } }',
                'variables' => [
                    'issueId' => $this->task->external_id,
                    'body' => $message,
                ],
            ]);
    }

    private function moveLinearToInReview(): void
    {
        $apiKey = (string) config('yak.channels.linear.api_key');

        if ($apiKey === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post('https://api.linear.app/graphql', [
                'query' => 'mutation($issueId: String!) { issueUpdate(id: $issueId, input: { stateId: "in-review" }) { success } }',
                'variables' => [
                    'issueId' => $this->task->external_id,
                ],
            ]);
    }

    private function cleanupBranch(Repository $repository): void
    {
        Process::path($repository->path)
            ->run("git checkout {$repository->default_branch}");

        if ($this->task->branch_name) {
            Process::path($repository->path)
                ->run("git branch -D {$this->task->branch_name}");
        }
    }
}
