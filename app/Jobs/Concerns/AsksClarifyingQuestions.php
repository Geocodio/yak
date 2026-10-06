<?php

namespace App\Jobs\Concerns;

use App\Agents\ClaudeCodeOutputParser;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Facades\Telemetry;
use App\Jobs\SendNotificationJob;
use App\Models\DailyCost;
use App\Models\YakTask;
use App\Services\ClarificationMessage;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;

/**
 * Lets an agent job stop and ask structured questions. The job calls
 * askIfNeeded() right after the agent returns; when it returns true the job
 * stops without its normal finish and resumes later with the answers.
 *
 * @property YakTask $task
 */
trait AsksClarifyingQuestions
{
    protected function askIfNeeded(AgentRunResult $result): bool
    {
        if (! $result->needsClarification()) {
            return false;
        }

        if ($this->task->clarificationRoundCount() >= YakTask::MAX_CLARIFICATION_ROUNDS) {
            TaskLogger::warning($this->task, 'Questions ignored: the round limit is reached', [
                'questions' => count($result->clarificationQuestions),
            ]);

            return false;
        }

        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $this->task->recordClarificationRound(
            $result->clarificationQuestions,
            ClaudeCodeOutputParser::stripClarificationBlock($result->resultSummary),
        );

        $this->task->update([
            'status' => TaskStatus::AwaitingClarification,
            ...YakTask::clarificationDeadlines(),
        ]);

        TaskLogger::info($this->task, 'Clarification posted', ['questions' => count($result->clarificationQuestions)]);
        Telemetry::feature('clarification.asked', [
            'questions' => count($result->clarificationQuestions),
            'round' => $this->task->clarificationRoundCount(),
        ], task: $this->task);

        SendNotificationJob::dispatch(
            $this->task,
            NotificationType::Clarification,
            ClarificationMessage::asked($this->task->fresh()),
            personalize: false,
        );

        return true;
    }
}
