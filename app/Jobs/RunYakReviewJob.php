<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Models\DailyCost;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use App\Services\IncusSandboxManager;
use App\Services\ReviewOutputParser;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Support\PathMatcher;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunYakReviewJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(public YakTask $task)
    {
        $this->onQueue('yak-claude');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new EnsureRepoReady, new EnsureDailyBudget];
    }

    public function handle(AgentRunner $agent): void
    {
        TaskContext::set($this->task);

        try {
            $this->runTask($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runTask(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($repository === null || ! $repository->pr_review_enabled) {
            $this->task->update([
                'status' => TaskStatus::Failed,
                'error_log' => 'Repository missing or PR review not enabled',
                'completed_at' => now(),
            ]);

            return;
        }

        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;
        $metadata = $this->metadata();

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        TaskLogger::info($this->task, 'Picked up review task', ['pr' => $this->task->pr_url]);

        try {
            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created', ['container' => $containerName]);

            $this->checkoutPrHead($sandbox, $containerName, $metadata);
            $promptContext = $this->buildPromptContext($sandbox, $containerName, $repository, $metadata);

            $prompt = YakPromptBuilder::taskPrompt($this->task, $promptContext);

            $result = $agent->run(new AgentRunRequest(
                prompt: $prompt,
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
            ));

            if ($result->isError) {
                TaskMetricsAccumulator::applyFresh($this->task, $result);
                $this->handleError($result->resultSummary ?: 'Agent returned an error');

                return;
            }

            TaskMetricsAccumulator::applyFresh($this->task, $result);
            DailyCost::accumulate($result->costUsd);

            $parsed = app(ReviewOutputParser::class)->parse($result->resultSummary);

            $parsed = $this->filterFindings($parsed, $promptContext['pathExcludes']);

            $this->postReview($repository, $parsed, $metadata);

            $this->task->update([
                'status' => TaskStatus::Success,
                'completed_at' => now(),
                'result_summary' => "Reviewed PR #{$metadata['pr_number']} — " . count($parsed->findings) . ' findings',
                'model_used' => config('yak.default_model'),
            ]);

            TaskLogger::info($this->task, 'Review posted', ['findings' => count($parsed->findings)]);
        } catch (ClaudeAuthException $e) {
            Log::error('RunYakReviewJob auth failure', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunYakReviewJob failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());
        } finally {
            if ($containerName !== null) {
                $sandbox->destroy($containerName);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->task->context, true) ?: [];

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function checkoutPrHead(IncusSandboxManager $sandbox, string $containerName, array $metadata): void
    {
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $prNumber = (int) $metadata['pr_number'];
        $headBranch = (string) $this->task->branch_name;

        $installationId = (int) config('yak.channels.github.installation_id');
        if ($installationId > 0) {
            $token = app(GitHubAppService::class)->getInstallationToken($installationId);
            $sandbox->run(
                $containerName,
                'git config --global credential.https://github.com.helper ' .
                escapeshellarg("!f() { echo \"protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\"; }; f"),
                timeout: 10,
            );
        }

        $sandbox->run(
            $containerName,
            "cd {$workspace} && git fetch origin pull/{$prNumber}/head:" . escapeshellarg($headBranch),
            timeout: 60,
        );
        $sandbox->run(
            $containerName,
            "cd {$workspace} && git checkout " . escapeshellarg($headBranch),
            timeout: 30,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildPromptContext(
        IncusSandboxManager $sandbox,
        string $containerName,
        Repository $repository,
        array $metadata,
    ): array {
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $base = (string) $metadata['base_sha'];
        $head = (string) $metadata['head_sha'];

        $diffStat = $sandbox->run(
            $containerName,
            "cd {$workspace} && git diff --stat " . escapeshellarg($base) . '...' . escapeshellarg($head),
            timeout: 30,
        )->output();

        $changedList = $sandbox->run(
            $containerName,
            "cd {$workspace} && git diff --name-only " . escapeshellarg($base) . '...' . escapeshellarg($head),
            timeout: 30,
        )->output();

        $changedFiles = array_values(array_filter(array_map('trim', explode("\n", $changedList))));

        $pathExcludes = $repository->pr_review_path_excludes
            ?? (array) config('yak.pr_review.default_path_excludes', []);

        $changedFiles = array_values(array_filter(
            $changedFiles,
            fn (string $f): bool => ! PathMatcher::matches($f, $pathExcludes),
        ));

        return [
            'prNumber' => (int) $metadata['pr_number'],
            'prTitle' => (string) $metadata['title'],
            'prBody' => (string) $metadata['body'],
            'prAuthor' => (string) $metadata['author'],
            'baseBranch' => $repository->default_branch,
            'headBranch' => (string) $this->task->branch_name,
            'diffSummary' => trim($diffStat),
            'reviewScope' => (string) ($metadata['review_scope'] ?? 'full'),
            'changedFiles' => $changedFiles,
            'repoAgentInstructions' => (string) ($repository->agent_instructions ?? ''),
            'pathExcludes' => $pathExcludes,
            'linearTicket' => null,
        ];
    }

    /**
     * @param  array<int, string>  $pathExcludes
     */
    private function filterFindings(ParsedReview $parsed, array $pathExcludes): ParsedReview
    {
        $allowed = array_values(array_filter(
            $parsed->findings,
            fn ($f): bool => ! PathMatcher::matches($f->file, $pathExcludes),
        ));

        return new ParsedReview(
            summary: $parsed->summary,
            verdict: $parsed->verdict,
            verdictDetail: $parsed->verdictDetail,
            findings: $allowed,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function postReview(Repository $repository, ParsedReview $parsed, array $metadata): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $maxFindings = (int) config('yak.pr_review.max_findings_per_review', 20);

        $findings = array_slice($parsed->findings, 0, $maxFindings);

        $lineComments = [];
        $nitpicks = [];

        foreach ($findings as $f) {
            if ($f->severity === 'consider') {
                $nitpicks[] = $f;

                continue;
            }

            $lineComments[] = [
                'path' => $f->file,
                'line' => $f->line,
                'body' => "**[{$f->category} · " . strtoupper($f->severity) . "]**\n\n{$f->body}",
            ];
        }

        $body = $this->renderReviewBody($parsed, $nitpicks);

        $response = app(GitHubAppService::class)->createPullRequestReview(
            $installationId,
            $repository->slug,
            (int) $metadata['pr_number'],
            $body,
            'COMMENT',
            $lineComments,
        );

        $review = PrReview::create([
            'yak_task_id' => $this->task->id,
            'repo' => $repository->slug,
            'pr_number' => (int) $metadata['pr_number'],
            'pr_url' => $this->task->pr_url,
            'github_review_id' => $response['id'] ?? null,
            'commit_sha_reviewed' => (string) $metadata['head_sha'],
            'review_scope' => (string) ($metadata['review_scope'] ?? 'full'),
            'incremental_base_sha' => $metadata['incremental_base_sha'] ?? null,
            'summary' => $parsed->summary,
            'verdict' => $parsed->verdict,
            'submitted_at' => now(),
        ]);

        $nonConsider = array_values(array_filter(
            $findings,
            fn ($f): bool => $f->severity !== 'consider',
        ));

        foreach ($response['comments'] ?? [] as $i => $returned) {
            $finding = $lineComments[$i] ?? null;
            if ($finding === null) {
                continue;
            }

            $original = $nonConsider[$i] ?? null;
            if ($original === null) {
                continue;
            }

            PrReviewComment::create([
                'pr_review_id' => $review->id,
                'github_comment_id' => (int) $returned['id'],
                'file_path' => $original->file,
                'line_number' => $original->line,
                'body' => $finding['body'],
                'category' => $original->category,
                'severity' => $original->severity,
                'is_suggestion' => $original->suggestionLoc !== null,
            ]);
        }
    }

    /**
     * @param  array<int, ReviewFinding>  $nitpicks
     */
    private function renderReviewBody(ParsedReview $parsed, array $nitpicks): string
    {
        $parts = [];
        $parts[] = "## Summary\n\n" . $parsed->summary;

        if ($nitpicks !== []) {
            $nitsBody = collect($nitpicks)
                ->map(fn ($n) => "- **{$n->file}:{$n->line}** — _{$n->category}_: {$n->body}")
                ->implode("\n");

            $parts[] = "<details>\n<summary>Nitpicks (" . count($nitpicks) . ")</summary>\n\n" . $nitsBody . "\n</details>";
        }

        $parts[] = "## Verdict\n\n**{$parsed->verdict}** — {$parsed->verdictDetail}";

        return implode("\n\n", $parts);
    }

    private function handleError(string $message): void
    {
        if ($this->task->fresh()?->status === TaskStatus::Cancelled) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $message,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Review failed', ['error' => $message]);
    }
}
