<?php

namespace App\Jobs\Concerns;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Services\TaskLogger;

trait RetriesWithoutStaleSession
{
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

        return $agent->run($request->withoutResume());
    }
}
