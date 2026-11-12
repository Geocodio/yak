<?php

namespace App\Jobs\AfterTurn;

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ExternalBranchPushException;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesFollowUpRuns;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Models\Repository;
use App\Services\Telemetry\RunRecorder;

/**
 * The work after a follow-up turn has ended.
 */
class FinishFollowUpTurnJob extends FinishThreadTurnJob
{
    use AsksClarifyingQuestions;
    use FinishesFollowUpRuns;
    use GuardsTerminalTaskStatus;
    use NotifiesSourceOfFailure;
    use ResumesAgentOnExistingBranch;

    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $container, RunRecorder $recorder): void
    {
        // The answers are delivered once the agent has returned, so a turn that never ended keeps them.
        $this->consumeAnswers();

        $this->finishFollowUpResult($repository, $result, $sandbox, $container, $recorder);
    }

    protected function reportFailure(string $message, ?\Throwable $cause = null): void
    {
        $this->handleError($message, isUserFacing: $cause instanceof ExternalBranchPushException);
    }

    protected function afterTurn(): void
    {
        $this->finishParkingForQuestions();
    }
}
