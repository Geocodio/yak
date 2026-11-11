<?php

namespace App\Jobs\Concerns;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Services\TaskLogger;
use App\YakPromptBuilder;

trait RetriesWithoutStaleSession
{
    /**
     * Prompt for the fresh run when it differs from the resumed run's
     * prompt, such as the first-run prompt plus the answers to questions.
     */
    protected ?string $staleSessionFallbackPrompt = null;

    /**
     * Run the agent, falling back to a fresh (non-resumed) run when the
     * CLI reports the resume session's transcript is missing.
     *
     * Session transcripts live inside the sandbox that produced them; if
     * they weren't persisted before that sandbox was destroyed, `--resume`
     * fails with "No conversation found with session ID". The follow-up
     * prompt plus the checked-out branch is enough context to proceed
     * without the old conversation, so retry once without resume rather
     * than failing the task.
     */
    protected function runAgentWithStaleSessionFallback(AgentRunner $agent, AgentRunRequest $request): AgentRunResult
    {
        $result = $agent->run($request);

        if (! $request->isResume() || ! $result->isStaleSessionResume()) {
            return $result;
        }

        if ($request->task !== null) {
            TaskLogger::warning($request->task, 'Session transcript missing — retrying without resume', [
                'resume_session_id' => $request->resumeSessionId,
            ]);
        }

        return $agent->run($request->withoutResume($this->staleSessionFallbackPrompt))->withStaleSessionRetry();
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
}
