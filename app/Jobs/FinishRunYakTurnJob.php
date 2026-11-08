<?php

namespace App\Jobs;

use App\DataTransferObjects\AgentRunResult;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesAgentRuns;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Models\Repository;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\RunRecorder;

/**
 * The work after an initial or clarification turn has ended.
 */
class FinishRunYakTurnJob extends FinishThreadTurnJob
{
    use AsksClarifyingQuestions;
    use FinishesAgentRuns;
    use HandlesWrongRepository;
    use NotifiesSourceOfFailure;

    protected function finishTurn(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $container, RunRecorder $recorder): void
    {
        $this->finishAgentResult($repository, $result, $sandbox, $container, $recorder);
    }

    protected function reportFailure(string $message, ?\Throwable $cause = null): void
    {
        $this->handleError($message);
    }

    protected function afterTurn(): void
    {
        $this->finishParkingForQuestions();
    }
}
