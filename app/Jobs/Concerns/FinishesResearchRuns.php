<?php

namespace App\Jobs\Concerns;

use App\Agents\ClaudeCodeOutputParser;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\TaskStop;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Services\ArtifactPersister;
use App\Services\ConcernsDraft;
use App\Services\ConfigPullRequests;
use App\Services\RepositoryConfig;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\YakConfigFiles;
use App\Services\YakPersonality;
use App\Support\MarkdownText;
use App\Support\TaskPageUrl;

trait FinishesResearchRuns
{
    private function finishResearchResult(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $containerName): void
    {
        if ($result->isError) {
            TaskMetricsAccumulator::record($this->task, $result);
            $this->handleError($result->failureMessage(), stop: $result->stop());

            return;
        }

        if ($this->askIfNeeded($result)) {
            return;
        }

        // Questions ignored at the round limit must not reach the output.
        $result = $result->withResultSummary(ClaudeCodeOutputParser::stripClarificationBlock($result->resultSummary));

        $this->handleSuccess($repository, $result, $sandbox, $containerName);
    }

    /**
     * Opens or updates the PR carrying the drafted concerns as `.yak/risk-profile.yml`,
     * only after a clean read of GitHub. A failure is reported in the returned
     * summary text and never loses the work.
     *
     * @param  list<array{name: string, approval: string, description: string}>  $concerns
     */
    private function openConcernsPullRequest(Repository $repository, array $concerns, string $sourceSha): string
    {
        try {
            $snapshot = app(RepositoryConfig::class)->snapshot($repository);

            if ($snapshot->state !== 'read') {
                return "\n\nNo risk concerns PR was opened: Yak could not read `.yak/` from GitHub. Generate the concerns again once it is reachable.";
            }

            $current = $repository->settings()->concerns();
            $currentConcerns = array_column($current ?? [], null, 'name');
            $changes = [];
            foreach ($concerns as $concern) {
                $old = $currentConcerns[$concern['name']] ?? null;
                if ($old === null) {
                    $changes[$concern['name']] = "Added, **{$concern['approval']}**";
                } elseif ($old['approval'] !== $concern['approval'] || $old['description'] !== $concern['description']) {
                    $changes[$concern['name']] = $old['approval'] !== $concern['approval']
                        ? "Changed, {$old['approval']} to **{$concern['approval']}**"
                        : 'Changed, description updated';
                }
            }
            foreach (array_diff(array_keys($currentConcerns), array_column($concerns, 'name')) as $name) {
                $changes[$name] = 'Removed';
            }

            $body = view('pull-requests.concerns', [
                'shortSha' => substr($sourceSha, 0, 7),
                'changes' => $changes,
            ])->render();
            $title = $current === null ? 'Add Yak risk concerns' : 'Update Yak risk concerns';

            $pullRequest = app(ConfigPullRequests::class)->open(
                $repository, 'yak/risk-profile',
                ['.yak/risk-profile.yml' => app(YakConfigFiles::class)->concerns($concerns)],
                $title, $body, $title, true,
            );
            TaskLogger::info($this->task, 'Opened risk concerns PR', ['url' => $pullRequest['url']]);

            return "\n\nRisk concerns PR: " . $pullRequest['url'];
        } catch (\Throwable $e) {
            TaskLogger::warning($this->task, 'Could not open the risk concerns PR: ' . $e->getMessage());

            return "\n\nCould not open the risk concerns PR: " . MarkdownText::inline($e->getMessage());
        }
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $containerName): void
    {
        $summary = $result->resultSummary;

        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $context = json_decode((string) $this->task->context, true) ?: [];
        if (($context['risk_profile_draft'] ?? false) === true) {
            // A rejected draft must not discard the run: the agent output is
            // the only copy of the work, and a human needs it to correct it.
            try {
                $concerns = ConcernsDraft::parse($summary);
                $summary .= $this->openConcernsPullRequest($repository, $concerns, (string) ($context['risk_profile_source_sha'] ?? ''));
            } catch (\Throwable $e) {
                $summary .= "\n\nNo risk concerns PR was opened: " . $e->getMessage()
                    . "\nCorrect the JSON above and run the risk profile draft again.";
                TaskLogger::error($this->task, 'Risk profile draft rejected', [
                    'error' => $e->getMessage(),
                    'raw_output' => mb_substr($result->resultSummary, 0, 10000),
                ]);
            }
        }

        $artifact = $this->collectHtmlArtifact($sandbox, $containerName);
        $artifactUrl = $artifact !== null ? $this->viewerUrl($artifact) : null;

        // A research run can record a walkthrough. Its shot clips only exist
        // inside the sandbox, and the persister hands them to the renderer.
        SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);
        $hasWalkthrough = collect(ArtifactPersister::persist($this->task))->contains('type', 'video');
        $taskUrl = TaskPageUrl::for($this->task);

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $summary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        TaskLogger::info($this->task, 'Task completed');

        // Generate the personality message from the summary only. If
        // we pipe the URL through the agent it sometimes paraphrases
        // the findings link into prose ("check out the link!") and
        // drops the actual URL -- which is exactly what the user sees.
        // Append the URL ourselves after the rewrite so it's always
        // front-and-center.
        //
        // When there's no artifact the task was a pure Q&A, so drop
        // the "Research complete:" prefix -- it reads oddly for a
        // short factual answer.
        if ($artifact !== null) {
            $notificationMessage = YakPersonality::generate(NotificationType::Result, "Research complete: {$summary}");
            $notificationMessage .= "\n\n📑 **[View research report]({$artifactUrl})**";
        } else {
            $notificationMessage = YakPersonality::generate(NotificationType::Result, $summary);
        }

        if ($hasWalkthrough && $taskUrl !== null) {
            $notificationMessage .= "\n\n🎬 **[Watch the walkthrough]({$taskUrl})** (ready once it finishes rendering)";
        }

        $this->reportResult($notificationMessage);

        if ($this->task->source === 'linear') {
            // Mirror the viewer URL onto the Linear issue itself so the
            // research report stays findable long after the agent
            // session wraps up.
            if ($artifact !== null && $artifactUrl !== null) {
                app(LinearNotificationDriver::class)->createIssueAttachment(
                    $this->task,
                    title: 'Research report',
                    url: $artifactUrl,
                    subtitle: 'Detailed findings from Yak · HTML',
                );
            }

            if ($hasWalkthrough && $taskUrl !== null) {
                app(LinearNotificationDriver::class)->createIssueAttachment(
                    $this->task,
                    title: 'Walkthrough video',
                    url: $taskUrl,
                    subtitle: 'Recorded by Yak',
                );
            }
        }
    }

    private function handleError(string $errorMessage, ?TaskStop $stop = null): void
    {
        // Don't overwrite a task that's already terminal -- see
        // RunYakJob::handleError() for the full reasoning.
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        $this->notifySourceOfFailure($errorMessage, $stop);
    }
}
