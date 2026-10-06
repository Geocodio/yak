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
use App\YakPromptBuilder;

/**
 * Lets an agent job stop and ask structured questions. The job calls
 * askIfNeeded() right after the agent returns; when it returns true the job
 * stops without its normal finish and resumes later with the answers.
 *
 * The task only moves to AwaitingClarification, and the questions are only
 * posted, in finishParkingForQuestions(), which the job calls once the
 * sandbox is torn down and the session transcript is pulled. Answers are
 * accepted only in that status, so a resume never races the teardown.
 *
 * @property YakTask $task
 * @property ?string $staleSessionFallbackPrompt
 */
trait AsksClarifyingQuestions
{
    /**
     * Set once a round is recorded; the job parks the task when it finishes.
     */
    private bool $parkedForQuestions = false;

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

        $this->parkedForQuestions = true;

        return true;
    }

    /**
     * Moves a task that asked questions to AwaitingClarification and posts
     * them. Called from the job's outermost finally, after teardown, so it
     * runs even when teardown throws. A task cancelled meanwhile stays put.
     */
    protected function finishParkingForQuestions(): void
    {
        if (! $this->parkedForQuestions) {
            return;
        }

        $this->parkedForQuestions = false;
        $this->task->refresh();

        if ($this->taskIsTerminal($this->task)) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::AwaitingClarification,
            ...YakTask::clarificationDeadlines(),
        ]);

        $questionCount = count($this->task->pendingClarificationQuestions());

        TaskLogger::info($this->task, 'Clarification posted', ['questions' => $questionCount]);
        Telemetry::feature('clarification.asked', [
            'questions' => $questionCount,
            'round' => $this->task->clarificationRoundCount(),
        ], task: $this->task);

        SendNotificationJob::dispatch(
            $this->task,
            NotificationType::Clarification,
            ClarificationMessage::asked($this->task->fresh()),
            personalize: false,
        );
    }

    /**
     * True when this run continues an earlier run with answers to its questions.
     */
    protected function resumingWithAnswers(): bool
    {
        return $this->task->clarificationAnswersAwaitingResume() !== null
            && $this->task->session_id !== null;
    }

    /**
     * The answers prompt when resuming, otherwise the job's first-run prompt.
     *
     * @param  callable(): string  $firstRunPrompt
     */
    protected function promptFor(callable $firstRunPrompt): string
    {
        if (! $this->resumingWithAnswers()) {
            return $firstRunPrompt();
        }

        $answersPrompt = YakPromptBuilder::clarificationAnswersPrompt($this->task);

        // Without the old session the agent needs the task itself, then the answers.
        $this->staleSessionFallbackPrompt = $firstRunPrompt() . "\n\n" . $answersPrompt;

        return $answersPrompt;
    }

    /**
     * Called once the agent has returned, so a resume interrupted before that
     * point delivers the answers again on its next attempt.
     */
    protected function consumeAnswers(): void
    {
        $this->task->markClarificationAnswersConsumed();
    }
}
