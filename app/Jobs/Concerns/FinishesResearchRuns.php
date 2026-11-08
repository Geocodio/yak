<?php

namespace App\Jobs\Concerns;

use App\Agents\ClaudeCodeOutputParser;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Services\ArtifactPersister;
use App\Services\ConfigPullRequests;
use App\Services\IncusSandboxManager;
use App\Services\RepositoryConfig;
use App\Services\RepositoryRiskProfiles;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\YakConfigFiles;
use App\Services\YakPersonality;
use App\Support\MarkdownText;

trait FinishesResearchRuns
{
    private function finishResearchResult(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        if ($result->isError) {
            TaskMetricsAccumulator::record($this->task, $result);
            $this->handleError($result->failureMessage());

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
     * Opens or updates the PR carrying the drafted profile as `.yak/risk-profile.yml`,
     * only after a clean read of GitHub. The database draft is already saved, so a
     * failure is reported in the returned summary text and never loses the work.
     *
     * @param  array<string, mixed>  $profile
     */
    private function openRiskProfilePullRequest(Repository $repository, array $profile): string
    {
        try {
            $snapshot = app(RepositoryConfig::class)->snapshot($repository);

            if ($snapshot->state !== 'read') {
                return "\n\nNo risk profile PR was opened: Yak could not read `.yak/` from GitHub. Generate the profile again once it is reachable.";
            }

            $current = $repository->settings()->riskProfile();
            $currentRisks = array_column($current['areas'] ?? [], 'risk', 'name');
            $changes = [];
            foreach ($profile['areas'] as $area) {
                $old = $currentRisks[$area['name']] ?? null;
                if ($old === null) {
                    $changes[$area['name']] = "New, **{$area['risk']}**";
                } elseif ($old !== $area['risk']) {
                    $changes[$area['name']] = "{$old} to **{$area['risk']}**";
                }
            }
            foreach (array_diff(array_keys($currentRisks), array_column($profile['areas'], 'name')) as $name) {
                $changes[$name] = 'Removed';
            }

            $body = view('pull-requests.risk-profile', [
                'shortSha' => substr((string) $profile['source_sha'], 0, 7),
                'changes' => $changes,
                'unknownCount' => count($profile['unknowns']),
            ])->render();
            $title = $current === null ? 'Add Yak risk profile' : 'Update Yak risk profile';

            $pullRequest = app(ConfigPullRequests::class)->open(
                $repository, 'yak/risk-profile',
                ['.yak/risk-profile.yml' => app(YakConfigFiles::class)->riskProfile($profile)],
                $title, $body, $title, true,
            );
            TaskLogger::info($this->task, 'Opened risk profile PR', ['url' => $pullRequest['url']]);

            return "\n\nRisk profile PR: " . $pullRequest['url'];
        } catch (\Throwable $e) {
            TaskLogger::warning($this->task, 'Could not open the risk profile PR: ' . $e->getMessage());

            return "\n\nCould not open the risk profile PR: " . MarkdownText::inline($e->getMessage());
        }
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        $summary = $result->resultSummary;

        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $context = json_decode((string) $this->task->context, true) ?: [];
        if (($context['risk_profile_draft'] ?? false) === true) {
            // A rejected draft must not discard the run: the agent output is
            // the only copy of the work, and a human needs it to correct the
            // JSON and re-import it. No draft is stored, so nothing can be
            // approved from a malformed profile either way.
            try {
                $profile = app(RepositoryRiskProfiles::class)->draft(
                    $repository->slug, (string) ($context['risk_profile_source_sha'] ?? ''), $summary,
                );
                $summary .= "\n\nDraft risk profile: " . $profile['version'] . "\nHuman review and explicit activation required.";
                TaskLogger::info($this->task, 'Risk profile draft saved', [
                    'version' => $profile['version'], 'source_sha' => $profile['source_sha'],
                ]);
                $summary .= $this->openRiskProfilePullRequest($repository, $profile);
            } catch (\Throwable $e) {
                $summary .= "\n\nNo draft risk profile was saved: " . $e->getMessage()
                    . "\nCorrect the JSON above and import it with `php artisan yak:risk-profile "
                    . $repository->slug . ' --import=<file.json>`.';
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
        $taskUrl = route('tasks.show', $this->task);

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
        // drops the actual URL — which is exactly what the user sees.
        // Append the URL ourselves after the rewrite so it's always
        // front-and-center.
        //
        // When there's no artifact the task was a pure Q&A, so drop
        // the "Research complete:" prefix — it reads oddly for a
        // short factual answer.
        if ($artifact !== null) {
            $notificationMessage = YakPersonality::generate(NotificationType::Result, "Research complete: {$summary}");
            $notificationMessage .= "\n\n📑 **[View research report]({$artifactUrl})**";
        } else {
            $notificationMessage = YakPersonality::generate(NotificationType::Result, $summary);
        }

        if ($hasWalkthrough) {
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

            if ($hasWalkthrough) {
                app(LinearNotificationDriver::class)->createIssueAttachment(
                    $this->task,
                    title: 'Walkthrough video',
                    url: $taskUrl,
                    subtitle: 'Recorded by Yak',
                );
            }
        }
    }

    private function handleError(string $errorMessage): void
    {
        // Don't overwrite a task that's already terminal — see
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

        $this->notifySourceOfFailure($errorMessage);
    }
}
