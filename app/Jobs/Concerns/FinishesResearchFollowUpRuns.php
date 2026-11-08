<?php

namespace App\Jobs\Concerns;

use App\Agents\ClaudeCodeOutputParser;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\DailyCost;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\YakPersonality;

trait FinishesResearchFollowUpRuns
{
    private function finishResearchFollowUpResult(AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
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

        $this->handleSuccess($result, $sandbox, $containerName);
    }

    private function handleSuccess(AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        $summary = $result->resultSummary;

        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $artifact = $this->collectHtmlArtifact($sandbox, $containerName);
        $artifactUrl = $artifact !== null ? $this->viewerUrl($artifact) : null;

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $summary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        TaskLogger::info($this->task, 'Task completed');

        // The link is appended after the personality rewrite so the agent
        // cannot paraphrase it away.
        $notificationMessage = YakPersonality::generate(NotificationType::Result, $summary);

        if ($artifactUrl !== null) {
            $notificationMessage .= "\n\n📑 **[View revised research report]({$artifactUrl})**";
        }

        $this->reportResult($notificationMessage);

        if ($this->task->source === 'linear') {
            if ($artifactUrl !== null) {
                app(LinearNotificationDriver::class)->createIssueAttachment(
                    $this->task,
                    title: 'Revised research report',
                    url: $artifactUrl,
                    subtitle: 'Detailed findings from Yak · HTML',
                );
            }
        }
    }

    private function handleError(string $errorMessage): void
    {
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        TaskLogger::error($this->task, 'Research follow-up failed', ['error' => $errorMessage]);

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        $this->notifySourceOfFailure($errorMessage);
    }
}
