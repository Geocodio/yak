<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\IdentifierExtractor as LinearIdentifierExtractor;
use App\Channels\Linear\IssueFetcher as LinearIssueFetcher;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\DailyCost;
use App\Models\LinearOauthConnection;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\ReviewOutputParser;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Support\GitHubDiffLines;
use App\Support\PathMatcher;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunYakReviewJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

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
        return [new PausesDuringDrain, new EnsureRepoReady, new EnsureDailyBudget];
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

            try {
                $parsed = app(ReviewOutputParser::class)->parse($result->resultSummary);
            } catch (\Throwable $e) {
                TaskLogger::error($this->task, 'Failed to parse agent review output', [
                    'error' => $e->getMessage(),
                    'raw_output' => mb_substr($result->resultSummary, 0, 10000),
                ]);

                throw $e;
            }

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
                $sandbox->pullClaudeCredentials($containerName);
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
        $headSha = (string) $metadata['head_sha'];

        // Use a locally-generated branch name rooted at the PR number so
        // the checkout never depends on whatever happens to be stored in
        // $task->branch_name (which other jobs mutate for push flows).
        $localBranch = "yak-review/pr-{$prNumber}";

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

        // Fetch the PR's head commit and check it out at the exact SHA
        // stored on the task. Pinning to the SHA guards against races with
        // new pushes that arrive between enqueue and execution.
        $sandbox->run(
            $containerName,
            "cd {$workspace} && git fetch origin pull/{$prNumber}/head:" . escapeshellarg($localBranch),
            timeout: 60,
        );
        $sandbox->run(
            $containerName,
            "cd {$workspace} && git checkout " . escapeshellarg($headSha),
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
        array &$metadata,
    ): array {
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $head = (string) $metadata['head_sha'];

        $scope = (string) ($metadata['review_scope'] ?? 'full');
        $incrementalBase = $metadata['incremental_base_sha'] ?? null;

        $effectiveBase = $scope === 'incremental' && $incrementalBase !== null
            ? (string) $incrementalBase
            : (string) $metadata['base_sha'];

        if ($scope === 'incremental' && $incrementalBase !== null) {
            $fetchResult = $sandbox->run(
                $containerName,
                "cd {$workspace} && git fetch origin " . escapeshellarg((string) $incrementalBase),
                timeout: 30,
            );

            if ($fetchResult->exitCode() !== 0) {
                Log::warning('Incremental base SHA not fetchable, degrading to full', [
                    'task_id' => $this->task->id,
                    'base_sha' => $incrementalBase,
                ]);

                $scope = 'full';
                $effectiveBase = (string) $metadata['base_sha'];
                $metadata['review_scope'] = 'full';
                $metadata['incremental_base_sha'] = null;

                $this->task->update([
                    'context' => json_encode($metadata),
                ]);
            }
        }

        $diffStat = $sandbox->run(
            $containerName,
            "cd {$workspace} && git diff --stat " . escapeshellarg($effectiveBase) . '...' . escapeshellarg($head),
            timeout: 30,
        )->output();

        $changedList = $sandbox->run(
            $containerName,
            "cd {$workspace} && git diff --name-only " . escapeshellarg($effectiveBase) . '...' . escapeshellarg($head),
            timeout: 30,
        )->output();

        $changedFiles = array_values(array_filter(array_map('trim', explode("\n", $changedList))));

        /** @var array<int, string> $pathExcludes */
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
            'baseBranch' => (string) ($metadata['base_ref'] ?? $repository->default_branch),
            'headBranch' => (string) ($metadata['head_ref'] ?? ''),
            'diffSummary' => trim($diffStat),
            'reviewScope' => $scope,
            'changedFiles' => $changedFiles,
            'repoAgentInstructions' => (string) ($repository->agent_instructions ?? ''),
            'pathExcludes' => $pathExcludes,
            'linearTicket' => $this->tryFetchLinearTicket($metadata),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>|null
     */
    private function tryFetchLinearTicket(array $metadata): ?array
    {
        $haystack = ((string) ($metadata['body'] ?? '')) . "\n" . ((string) ($metadata['title'] ?? ''));
        $identifier = LinearIdentifierExtractor::firstFrom($haystack);

        if ($identifier === null) {
            return null;
        }

        if (LinearOauthConnection::query()->exists() === false) {
            return null;
        }

        try {
            $issue = app(LinearIssueFetcher::class)->fetch($identifier);

            if ($issue === null) {
                return null;
            }

            return [
                'identifier' => (string) $issue['identifier'],
                'title' => (string) $issue['title'],
                'description' => (string) $issue['description'],
                'url' => (string) $issue['url'],
            ];
        } catch (\Throwable $e) {
            Log::info('Linear fetch failed for review', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
        $github = app(GitHubAppService::class);
        $prNumber = (int) $metadata['pr_number'];

        $findings = array_slice($parsed->findings, 0, $maxFindings);

        // Ask GitHub which (file, line) pairs live inside a diff hunk —
        // only those can carry a review comment without being rejected
        // with a 422. Everything else gets rolled into the review body.
        $prFiles = $github->listPullRequestFiles($installationId, $repository->slug, $prNumber);
        $validLines = GitHubDiffLines::buildMap($prFiles);

        $lineComments = [];
        $lineCommentFindings = [];
        $nitpicks = [];
        $outOfDiff = [];

        foreach ($findings as $f) {
            $lineIsCommentable = isset($validLines[$f->file][$f->line]);

            if (! $lineIsCommentable) {
                if ($f->severity === 'consider') {
                    $nitpicks[] = $f;
                } else {
                    $outOfDiff[] = $f;
                }

                continue;
            }

            $severityLabel = $f->severity === 'consider' ? 'NITPICK' : strtoupper($f->severity);

            $lineComments[] = [
                'path' => $f->file,
                'line' => $f->line,
                'body' => "**[{$f->category} · {$severityLabel}]**\n\n{$f->body}",
            ];
            $lineCommentFindings[] = $f;
        }

        if ($outOfDiff !== []) {
            TaskLogger::info($this->task, 'Some findings landed outside the diff hunks — folded into review body', [
                'out_of_diff_count' => count($outOfDiff),
                'line_comment_count' => count($lineComments),
            ]);
        }

        $body = $this->renderReviewBody($parsed, $nitpicks, $outOfDiff);

        try {
            $response = $github->createPullRequestReview(
                $installationId,
                $repository->slug,
                $prNumber,
                $body,
                'COMMENT',
                $lineComments,
            );
        } catch (\RuntimeException $e) {
            // GitHub rejects line comments whose line number isn't inside a
            // diff hunk. Rather than lose the whole review, fold the
            // non-consider findings into the body and submit without
            // per-line comments.
            TaskLogger::warning($this->task, 'GitHub rejected line comments, falling back to body-only review', [
                'error' => $e->getMessage(),
                'line_comment_count' => count($lineComments),
            ]);

            $allNitpicks = array_values(array_filter(
                $findings,
                fn (ReviewFinding $f): bool => $f->severity === 'consider',
            ));
            $body = $this->renderReviewBodyWithInlineFindings($parsed, $findings, $allNitpicks);
            $response = $github->createPullRequestReview(
                $installationId,
                $repository->slug,
                $prNumber,
                $body,
                'COMMENT',
                [],
            );
            $lineComments = [];
            $lineCommentFindings = [];
        }

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

        if ((string) ($metadata['review_scope'] ?? 'full') === 'full') {
            $priorReviews = PrReview::where('pr_url', $this->task->pr_url)
                ->whereNull('dismissed_at')
                ->where('id', '!=', $review->id)
                ->get();

            foreach ($priorReviews as $prior) {
                if ($prior->github_review_id !== null) {
                    try {
                        app(GitHubAppService::class)->dismissPullRequestReview(
                            $installationId,
                            $repository->slug,
                            (int) $metadata['pr_number'],
                            (int) $prior->github_review_id,
                            'Superseded by newer commits',
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Failed to dismiss prior review', [
                            'prior_id' => $prior->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $prior->update(['dismissed_at' => now()]);
            }
        }

        foreach ($response['comments'] ?? [] as $i => $returned) {
            $finding = $lineComments[$i] ?? null;
            if ($finding === null) {
                continue;
            }

            $original = $lineCommentFindings[$i] ?? null;
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
     * @param  array<int, ReviewFinding>  $outOfDiff
     */
    private function renderReviewBody(ParsedReview $parsed, array $nitpicks, array $outOfDiff = []): string
    {
        $parts = [];
        $parts[] = "## Summary\n\n" . $parsed->summary;

        if ($outOfDiff !== []) {
            $byFile = [];
            foreach ($outOfDiff as $f) {
                $byFile[$f->file][] = $f;
            }

            $blocks = [];
            foreach ($byFile as $file => $items) {
                $lines = array_map(
                    fn (ReviewFinding $f): string => "- **[{$f->category} · " . strtoupper($f->severity) . "]** `{$f->file}:{$f->line}` — {$f->body}",
                    $items,
                );
                $blocks[] = implode("\n", $lines);
            }

            $parts[] = "## Findings outside the diff\n\nThese flag code that changed behavior indirectly or wasn't touched by this PR — they couldn't be inline-commented.\n\n" . implode("\n\n", $blocks);
        }

        if ($nitpicks !== []) {
            $nitsBody = collect($nitpicks)
                ->map(fn ($n) => "- **{$n->file}:{$n->line}** — _{$n->category}_: {$n->body}")
                ->implode("\n");

            $parts[] = "<details>\n<summary>Nitpicks (" . count($nitpicks) . ")</summary>\n\n" . $nitsBody . "\n</details>";
        }

        $parts[] = "## Verdict\n\n**{$parsed->verdict}** — {$parsed->verdictDetail}";

        return implode("\n\n", $parts);
    }

    /**
     * Fallback renderer used when GitHub rejects the per-line comments
     * (typically because a finding's line isn't inside a diff hunk).
     * Inlines every finding into the review body so the feedback still
     * reaches the PR author, just without the native per-line threading.
     *
     * @param  array<int, ReviewFinding>  $findings  all findings (including consider)
     * @param  array<int, ReviewFinding>  $nitpicks  already-separated consider findings
     */
    private function renderReviewBodyWithInlineFindings(ParsedReview $parsed, array $findings, array $nitpicks): string
    {
        $parts = [];
        $parts[] = "## Summary\n\n" . $parsed->summary;

        $grouped = ['must_fix' => [], 'should_fix' => []];
        foreach ($findings as $f) {
            if (isset($grouped[$f->severity])) {
                $grouped[$f->severity][] = $f;
            }
        }

        $sectionLabels = [
            'must_fix' => 'Must Fix',
            'should_fix' => 'Should Fix',
        ];

        foreach ($grouped as $severity => $items) {
            if ($items === []) {
                continue;
            }

            $lines = array_map(
                fn (ReviewFinding $f): string => "- **[{$f->category}]** `{$f->file}:{$f->line}` — {$f->body}",
                $items,
            );
            $parts[] = "## {$sectionLabels[$severity]}\n\n" . implode("\n", $lines);
        }

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
