<?php

namespace App\Channels\GitHub;

use App\Actions\EnqueuePrReview;
use App\Actions\RecordPullRequestOutcome;
use App\Http\Concerns\RecordsWebhookTelemetry;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\Deployments\DeployBranchJob;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\UpdateDeploymentJob;
use App\Jobs\FlushFollowUpBatchJob;
use App\Jobs\HandlePullRequestSummonJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunConfigCheckJob;
use App\Jobs\TriageReviewJob;
use App\Models\BranchDeployment;
use App\Models\FollowUpPendingComment;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\BranchDeploymentProvisioner;
use App\Services\RepositoryConfig;
use App\Services\TaskLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    use RecordsWebhookTelemetry;
    use VerifiesWebhookSignature;

    public function __invoke(Request $request, AppService $github): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.github.webhook_secret'),
            'X-Hub-Signature-256',
        );

        $event = (string) $request->header('X-GitHub-Event', '');
        $action = (string) $request->input('action', '');

        return $this->recordWebhook(
            'github',
            $action !== '' ? "{$event}.{$action}" : $event,
            fn (): JsonResponse => $this->route($request, $github, $event),
            ['repo' => (string) $request->input('repository.full_name', '')],
        );
    }

    private function route(Request $request, AppService $github, string $event): JsonResponse
    {
        if ($event === 'check_suite') {
            return $this->handleCheckSuite($request, $github);
        }

        if ($event === 'push') {
            return $this->handlePush($request);
        }

        if ($event === 'delete') {
            return $this->handleDeploymentDelete($request);
        }

        if ($event === 'issue_comment') {
            return $this->handleIssueComment($request, $github);
        }

        if ($event === 'pull_request_review_comment') {
            if ((bool) config('yak.followup.github_review_triage_enabled', true)) {
                return response()->json(['ok' => true, 'skipped' => 'handled by review triage']);
            }

            return $this->handlePullRequestReviewComment($request, $github);
        }

        if ($event === 'pull_request_review') {
            return $this->handlePullRequestReview($request, $github);
        }

        if ($event === 'repository') {
            return $this->handleRepositoryEvent($request);
        }

        if ($event !== 'pull_request') {
            return response()->json(['ok' => true, 'skipped' => "unhandled event: {$event}"]);
        }

        $action = $request->input('action');

        if ($action === 'closed') {
            $this->maybeDispatchDeployment($request, 'closed');

            return $this->handleClosed($request);
        }

        $this->maybeDispatchConfigCheck($request, (string) $action);

        if ($action === 'synchronize') {
            // Pushes still refresh the branch deployment, but no longer
            // auto-trigger a re-review — the author asks for one via
            // GitHub's "Re-request review" button when they're ready.
            $this->maybeDispatchDeployment($request, $action);

            return response()->json(['ok' => true, 'skipped' => 'synchronize does not trigger review']);
        }

        if (in_array($action, ['opened', 'ready_for_review', 'reopened'], true)) {
            $this->maybeDispatchDeployment($request, $action);

            return $this->handleReviewTrigger($request, $action);
        }

        if ($action === 'review_requested') {
            $requestedLogin = (string) $request->input('requested_reviewer.login', '');

            if ($requestedLogin === '' || $requestedLogin !== app(AppService::class)->appBotLogin()) {
                return response()->json(['ok' => true, 'skipped' => 'review requested from someone other than yak']);
            }

            return $this->handleReviewTrigger($request, 'review_requested');
        }

        return response()->json(['ok' => true, 'skipped' => "unhandled action: {$action}"]);
    }

    private function handleCheckSuite(Request $request, AppService $github): JsonResponse
    {
        if ($request->input('action') !== 'completed') {
            return response()->json(['ok' => true, 'skipped' => 'not a check_suite.completed event']);
        }

        /** @var string $branch */
        $branch = $request->input('check_suite.head_branch', '');

        if (! str_starts_with($branch, 'yak/')) {
            return response()->json(['ok' => true, 'skipped' => 'not a yak branch']);
        }

        // Follow-ups push to their parent's branch, so the newest task on the
        // branch is the one that pushed the commit CI just reported on.
        $task = YakTask::where('branch_name', $branch)->latest('id')->first();

        if (! $task) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for branch']);
        }

        $repository = Repository::where('slug', $task->repo)->first();

        if (! $repository || $repository->settings()->ciSystem() !== 'github_actions') {
            return response()->json(['ok' => true, 'skipped' => 'wrong CI system']);
        }

        /** @var string $conclusion */
        $conclusion = $request->input('check_suite.conclusion', 'failure');
        $passed = $conclusion === 'success';

        $output = null;
        if (! $passed) {
            $installationId = (int) config('yak.channels.github.installation_id');
            $commitSha = (string) $request->input('check_suite.head_sha', '');

            if ($installationId > 0 && $commitSha !== '') {
                if ($this->rerunFailedWorkflowsOnce($github, $task, $installationId, $repository->github_full_name, $commitSha)) {
                    return response()->json(['ok' => true, 'skipped' => 'rerunning failed jobs']);
                }

                $output = $github->getFailedCheckRunOutput($installationId, $repository->github_full_name, $commitSha);
            }
        }

        $headSha = (string) $request->input('check_suite.head_sha', '');

        ProcessCIResultJob::dispatch($task, $passed, $output, $headSha !== '' ? $headSha : null);

        return response()->json(['ok' => true, 'dispatched' => true]);
    }

    /**
     * Give each failed workflow run on a commit one re-run of its failed jobs
     * before the failure counts against the task.
     *
     * A flaky test otherwise costs a full agent retry, and when it flakes on
     * the last attempt the task fails outright. Only first attempts are
     * re-run, so a job that fails twice on the same commit reaches the agent.
     * The cache key absorbs duplicate check_suite events for the same run.
     * Returns true while a re-run is in flight, meaning the result is not
     * final yet.
     */
    private function rerunFailedWorkflowsOnce(AppService $github, YakTask $task, int $installationId, string $repoSlug, string $commitSha): bool
    {
        $failedFirstAttempts = array_filter(
            $github->listWorkflowRunsForCommit($installationId, $repoSlug, $commitSha),
            fn (array $run): bool => $run['status'] === 'completed'
                && in_array($run['conclusion'], ['failure', 'timed_out'], true)
                && (int) $run['run_attempt'] === 1,
        );

        $isRerunPending = false;
        $rerunWorkflowNames = [];

        foreach ($failedFirstAttempts as $run) {
            $cacheKey = "yak:ci-rerun:{$run['id']}";

            if (! Cache::add($cacheKey, true, now()->addDay())) {
                $isRerunPending = true;

                continue;
            }

            if ($github->rerunFailedJobs($installationId, $repoSlug, (int) $run['id'])) {
                $rerunWorkflowNames[] = $run['name'];
            } else {
                Cache::forget($cacheKey);
            }
        }

        if ($rerunWorkflowNames === []) {
            return $isRerunPending;
        }

        TaskLogger::info($task, 'CI failed, re-running the failed jobs once to rule out a flaky test', [
            'workflows' => $rerunWorkflowNames,
            'commit' => $commitSha,
        ]);

        // yak:timeout-ci measures from updated_at, so the re-run gets a full window.
        $task->touch();

        return true;
    }

    private function handleClosed(Request $request): JsonResponse
    {
        /** @var string $prUrl */
        $prUrl = $request->input('pull_request.html_url', '');
        $merged = (bool) $request->input('pull_request.merged', false);

        $recorded = app(RecordPullRequestOutcome::class)->record(
            $prUrl,
            merged: $merged,
            mergedAt: $request->input('pull_request.merged_at'),
            closedAt: $request->input('pull_request.closed_at'),
            via: 'webhook',
        );

        if (! $recorded) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for PR']);
        }

        return response()->json(['ok' => true, 'updated' => true]);
    }

    private function maybeDispatchConfigCheck(Request $request, string $action): void
    {
        if (! in_array($action, ['opened', 'reopened', 'ready_for_review', 'synchronize'], true)) {
            return;
        }

        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo !== null) {
            RunConfigCheckJob::dispatch(
                $repo->id,
                (int) $request->input('pull_request.number'),
                (string) $request->input('pull_request.head.sha'),
            );
        }
    }

    private function maybeDispatchDeployment(Request $request, string $action): void
    {
        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo === null || ! $repo->deployments_enabled) {
            return;
        }

        if (in_array($action, ['opened', 'reopened'], true)) {
            if ((int) ($repo->current_template_version ?? 0) < 1) {
                // Repo hasn't been set up under the versioned-template regime yet.
                // Log and skip; operator needs to re-run SetupYakJob.
                Log::warning('Skipping deployment: repo has no versioned template', [
                    'repository_id' => $repo->id,
                    'repository_slug' => $repo->slug,
                    'branch' => $request->input('pull_request.head.ref'),
                ]);

                return;
            }

            $branch = (string) $request->input('pull_request.head.ref');
            $sha = (string) $request->input('pull_request.head.sha');
            $prNumber = (int) $request->input('pull_request.number');

            $deployment = app(BranchDeploymentProvisioner::class)->provision($repo, $branch);
            $deployment->update([
                'pr_number' => $prNumber,
                'pr_state' => 'open',
                'current_commit_sha' => $sha,
            ]);
            DeployBranchJob::dispatch($deployment->id);

            return;
        }

        if ($action === 'synchronize') {
            $branch = (string) $request->input('pull_request.head.ref');
            $sha = (string) $request->input('pull_request.head.sha');

            $deployment = BranchDeployment::where('repository_id', $repo->id)
                ->where('branch_name', $branch)
                ->whereNotIn('status', ['destroying', 'destroyed'])
                ->first();

            if ($deployment !== null) {
                UpdateDeploymentJob::dispatch($deployment->id, $sha);
            }

            return;
        }

        if ($action === 'closed') {
            $branch = (string) $request->input('pull_request.head.ref');
            $merged = (bool) $request->input('pull_request.merged', false);

            $deployment = BranchDeployment::where('repository_id', $repo->id)
                ->where('branch_name', $branch)
                ->whereNotIn('status', ['destroying', 'destroyed'])
                ->first();

            if ($deployment !== null) {
                $deployment->update(['pr_state' => $merged ? 'merged' : 'closed']);
                DestroyDeploymentJob::dispatch($deployment->id);
            }
        }
    }

    private function handlePush(Request $request): JsonResponse
    {
        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo !== null && $request->input('ref') === 'refs/heads/' . $repo->default_branch) {
            app(RepositoryConfig::class)->forget($repo);
        }

        if ($repo === null || ! $repo->deployments_enabled) {
            return response()->json(['ok' => true, 'skipped' => 'deployments disabled or repo not found']);
        }

        $ref = (string) $request->input('ref', '');

        if (! str_starts_with($ref, 'refs/heads/')) {
            return response()->json(['ok' => true, 'skipped' => 'not a branch push']);
        }

        $branch = substr($ref, strlen('refs/heads/'));
        $sha = (string) $request->input('after');

        $deployment = BranchDeployment::where('repository_id', $repo->id)
            ->where('branch_name', $branch)
            ->whereNotIn('status', ['destroying', 'destroyed'])
            ->first();

        if ($deployment !== null) {
            UpdateDeploymentJob::dispatch($deployment->id, $sha);
        }

        return response()->json(['ok' => true]);
    }

    private function handleDeploymentDelete(Request $request): JsonResponse
    {
        if ((string) $request->input('ref_type') !== 'branch') {
            return response()->json(['ok' => true, 'skipped' => 'not a branch delete']);
        }

        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo === null || ! $repo->deployments_enabled) {
            return response()->json(['ok' => true, 'skipped' => 'deployments disabled or repo not found']);
        }

        $branch = (string) $request->input('ref');

        $deployment = BranchDeployment::where('repository_id', $repo->id)
            ->where('branch_name', $branch)
            ->whereNotIn('status', ['destroying', 'destroyed'])
            ->first();

        if ($deployment !== null) {
            DestroyDeploymentJob::dispatch($deployment->id);
        }

        return response()->json(['ok' => true]);
    }

    private function handleIssueComment(Request $request, AppService $github): JsonResponse
    {
        if ($request->input('action') !== 'created') {
            return response()->json(['ok' => true, 'skipped' => 'not a created comment']);
        }

        $issue = (array) $request->input('issue', []);

        if (! isset($issue['pull_request'])) {
            return response()->json(['ok' => true, 'skipped' => 'not a PR comment']);
        }

        $prUrl = (string) data_get($request->all(), 'issue.pull_request.html_url', '');

        $root = $prUrl !== '' ? YakTask::followUpRootForPr($prUrl) : null;

        if ($root === null || $root->targets_external_pr) {
            return $this->dispatchCommentSummon($request, $github);
        }

        return $this->processFollowUpComment($request, $github, $prUrl, isReviewComment: false);
    }

    /**
     * A top-level comment on a PR Yak did not open. Only a prefixed comment from
     * a person goes on; the job answers it either way.
     */
    private function dispatchCommentSummon(Request $request, AppService $github): JsonResponse
    {
        $comment = (array) $request->input('comment', []);

        /** @var array<string, mixed> $author */
        $author = (array) ($comment['user'] ?? []);

        if ($this->isBotAuthor($author, $github)) {
            return response()->json(['ok' => true, 'skipped' => 'yak authored comment']);
        }

        $body = (string) ($comment['body'] ?? '');

        if (app(FollowUpCommentParser::class)->parse($body) === null) {
            return response()->json(['ok' => true, 'skipped' => 'no yak prefix']);
        }

        HandlePullRequestSummonJob::dispatch(
            repoFullName: (string) $request->input('repository.full_name', ''),
            prNumber: (int) $request->input('issue.number'),
            summonerLogin: (string) ($author['login'] ?? ''),
            issueCommentId: (int) ($comment['id'] ?? 0),
            issueCommentBody: $body,
        );

        return response()->json(['ok' => true, 'summon' => true]);
    }

    private function handlePullRequestReviewComment(Request $request, AppService $github): JsonResponse
    {
        if ($request->input('action') !== 'created') {
            return response()->json(['ok' => true, 'skipped' => 'not a created comment']);
        }

        $prUrl = (string) $request->input('pull_request.html_url', '');

        return $this->processFollowUpComment($request, $github, $prUrl, isReviewComment: true);
    }

    /**
     * A submitted review is the unit of feedback: its summary body plus
     * every inline comment. The checks here are cheap and synchronous;
     * everything that touches the GitHub API or an LLM runs in
     * TriageReviewJob so the webhook answers inside GitHub's timeout.
     */
    private function handlePullRequestReview(Request $request, AppService $github): JsonResponse
    {
        if (! (bool) config('yak.followup.github_review_triage_enabled', true)) {
            return response()->json(['ok' => true, 'skipped' => 'review triage disabled']);
        }

        if ($request->input('action') !== 'submitted') {
            return response()->json(['ok' => true, 'skipped' => 'not a submitted review']);
        }

        $review = (array) $request->input('review', []);
        $reviewerLogin = (string) ($review['user']['login'] ?? '');

        /** @var array<string, mixed> $reviewer */
        $reviewer = (array) ($review['user'] ?? []);

        if ($this->isBotAuthor($reviewer, $github)) {
            return response()->json(['ok' => true, 'skipped' => 'yak authored review']);
        }

        $prUrl = (string) $request->input('pull_request.html_url', '');
        $task = $prUrl !== '' ? YakTask::followUpRootForPr($prUrl) : null;

        if ($task === null || $task->targets_external_pr) {
            HandlePullRequestSummonJob::dispatch(
                repoFullName: (string) $request->input('repository.full_name', ''),
                prNumber: (int) $request->input('pull_request.number'),
                summonerLogin: $reviewerLogin,
                reviewId: (int) ($review['id'] ?? 0),
                reviewBody: (string) ($review['body'] ?? ''),
            );

            return response()->json(['ok' => true, 'summon' => true]);
        }

        if (! $task->prIsOpen()) {
            return response()->json(['ok' => true, 'skipped' => 'pr not open']);
        }

        $state = strtolower((string) ($review['state'] ?? ''));
        $body = (string) ($review['body'] ?? '');

        if ($state === 'approved' && trim($body) === '') {
            return response()->json(['ok' => true, 'skipped' => 'empty approval']);
        }

        TriageReviewJob::dispatch(
            taskId: $task->id,
            reviewId: (int) ($review['id'] ?? 0),
            prNumber: (int) ($task->pr_number ?? $request->input('pull_request.number') ?? $this->extractPrNumber($prUrl)),
            reviewState: $state,
            reviewBody: $body,
            reviewerLogin: $reviewerLogin,
        );

        return response()->json(['ok' => true]);
    }

    private function processFollowUpComment(Request $request, AppService $github, string $prUrl, bool $isReviewComment): JsonResponse
    {
        $comment = (array) $request->input('comment', []);
        $authorLogin = (string) ($comment['user']['login'] ?? '');

        /** @var array<string, mixed> $author */
        $author = (array) ($comment['user'] ?? []);

        if ($this->isBotAuthor($author, $github)) {
            return response()->json(['ok' => true, 'skipped' => 'yak authored comment']);
        }

        $instructions = app(FollowUpCommentParser::class)->parse((string) ($comment['body'] ?? ''));

        if ($instructions === null) {
            return response()->json(['ok' => true, 'skipped' => 'no yak prefix']);
        }

        if ($prUrl === '') {
            return response()->json(['ok' => true, 'skipped' => 'no pr url']);
        }

        $task = YakTask::followUpRootForPr($prUrl);

        if ($task === null) {
            return response()->json(['ok' => true, 'skipped' => 'no yak task for pr']);
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $repoSlug = (string) ($request->input('repository.full_name') ?? Repository::githubNameFor((string) $task->repo));
        $commentId = (int) ($comment['id'] ?? 0);

        if ($installationId > 0 && $commentId > 0) {
            $github->addReaction($installationId, $repoSlug, $commentId, 'eyes', isReviewComment: $isReviewComment);
        }

        if (! $task->prIsOpen()) {
            if ($installationId > 0) {
                $prNumber = (int) ($task->pr_number ?? $this->extractPrNumber($prUrl));

                if ($prNumber > 0) {
                    $github->commentOnPullRequest(
                        $installationId,
                        $repoSlug,
                        $prNumber,
                        "This PR is already merged or closed, so I can't push more changes here. Open a new issue or task and I'll pick it up.",
                    );
                }
            }

            return response()->json(['ok' => true, 'skipped' => 'pr not open']);
        }

        $hadPending = FollowUpPendingComment::where('pr_url', $prUrl)->exists();

        FollowUpPendingComment::create([
            'yak_task_id' => $task->id,
            'pr_url' => $prUrl,
            'body' => $instructions,
            'author' => $authorLogin !== '' ? $authorLogin : null,
            'file' => $isReviewComment ? ($comment['path'] ?? null) : null,
            'line' => $isReviewComment ? ($comment['line'] ?? $comment['original_line'] ?? null) : null,
            'diff_hunk' => $isReviewComment ? ($comment['diff_hunk'] ?? null) : null,
            'github_comment_id' => $commentId ?: null,
        ]);

        if (! $hadPending) {
            FlushFollowUpBatchJob::dispatch($prUrl)
                ->delay(now()->addSeconds((int) config('yak.followup.github_batch_window_seconds', 60)));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GitHub marks App and integration accounts with `type: "Bot"`. Yak never
     * acts on a bot's review or comment: that covers its own output even when
     * the configured bot login drifts from the real App slug, and it keeps
     * third-party review bots from driving Yak.
     *
     * @param  array<string, mixed>  $user  the `user` object from a review or comment payload
     */
    private function isBotAuthor(array $user, AppService $github): bool
    {
        if ((string) ($user['type'] ?? '') === 'Bot') {
            return true;
        }

        $login = (string) ($user['login'] ?? '');

        if ($login === '') {
            return false;
        }

        $botLogin = $github->appBotLogin();

        if ($login === $botLogin) {
            return true;
        }

        if ($login . '[bot]' === $botLogin) {
            return true;
        }

        if ($botLogin . '[bot]' === $login) {
            return true;
        }

        return false;
    }

    private function extractPrNumber(string $prUrl): int
    {
        if (preg_match('#/pull/(\d+)#', $prUrl, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function resolveRepositoryFromPayload(Request $request): ?Repository
    {
        return Repository::resolveFromGitHub(
            $request->input('repository.id') !== null ? (int) $request->input('repository.id') : null,
            (string) $request->input('repository.full_name'),
        );
    }

    /**
     * Track GitHub renames and transfers so inbound webhooks keep resolving.
     *
     * Only the GitHub-side coordinates move. The slug stays put — it is the
     * FK for `tasks.repo`, the preview hostname base, the incus template
     * alias, and the on-disk clone path.
     */
    private function handleRepositoryEvent(Request $request): JsonResponse
    {
        $action = (string) $request->input('action');

        if (! in_array($action, ['renamed', 'transferred'], true)) {
            return response()->json(['ok' => true, 'skipped' => "unhandled repository action: {$action}"]);
        }

        $repository = $this->resolveRepositoryFromPayload($request);

        if ($repository === null) {
            return response()->json(['ok' => true, 'skipped' => 'repo not registered']);
        }

        $newFullName = (string) $request->input('repository.full_name');

        if ($newFullName === '') {
            return response()->json(['ok' => true, 'skipped' => 'payload carries no full name']);
        }

        $previousFullName = $repository->github_full_name;
        $updates = ['github_full_name' => $newFullName];

        $cloneUrl = (string) $request->input('repository.clone_url');

        if ($cloneUrl !== '') {
            $updates['git_url'] = $cloneUrl;
        }

        if ($repository->github_repo_id === null) {
            $repoId = (int) $request->input('repository.id');

            if ($repoId > 0) {
                $updates['github_repo_id'] = $repoId;
            }
        }

        $repository->update($updates);

        Log::info('GitHub repository ' . $action, [
            'repository_id' => $repository->id,
            'repository_slug' => $repository->slug,
            'from' => $previousFullName,
            'to' => $newFullName,
        ]);

        return response()->json(['ok' => true, 'updated' => true]);
    }

    private function handleReviewTrigger(Request $request, string $action): JsonResponse
    {
        $pr = (array) $request->input('pull_request', []);

        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo === null || ! $repo->is_active) {
            return response()->json(['ok' => true, 'skipped' => 'repo not registered or inactive']);
        }

        if (! $repo->settings()->reviewEnabled()) {
            return response()->json(['ok' => true, 'skipped' => 'pr review disabled on repo']);
        }

        if ((bool) ($pr['draft'] ?? false)) {
            return response()->json(['ok' => true, 'skipped' => 'draft PR']);
        }

        if (! config('yak.pr_review.self_review_enabled') && (string) ($pr['user']['login'] ?? '') === app(AppService::class)->appBotLogin()) {
            return response()->json(['ok' => true, 'skipped' => 'yak-authored PR']);
        }

        $scope = $action === 'review_requested' ? 'incremental' : 'full';
        $incrementalBase = null;

        if ($scope === 'incremental') {
            $prior = PrReview::where('pr_url', (string) $pr['html_url'])
                ->whereNull('dismissed_at')
                ->orderByDesc('submitted_at')
                ->first();

            if ($prior === null) {
                $scope = 'full';
            } else {
                $incrementalBase = $prior->commit_sha_reviewed;
            }
        }

        $task = app(EnqueuePrReview::class)->dispatch($repo, $pr, $scope, $incrementalBase);

        if ($task === null) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate']);
        }

        return response()->json(['ok' => true, 'enqueued' => $task->id]);
    }
}
