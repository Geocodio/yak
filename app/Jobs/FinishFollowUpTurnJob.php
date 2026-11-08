<?php

namespace App\Jobs;

use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ExternalBranchPushException;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesFollowUpRuns;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Models\Repository;
use App\Services\IncusSandboxManager;
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

    protected function finishTurn(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $container, RunRecorder $recorder): void
    {
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
