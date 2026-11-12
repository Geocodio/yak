<?php

namespace App\Jobs\AfterTurn;

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesAgentRuns;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Models\Repository;
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

    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void
    {
        // The answers of a clarification turn are delivered once the agent has returned, so a turn that never ended keeps them.
        $this->consumeAnswers();

        $this->finishAgentResult($repository, $result, $shell, $container, $recorder);
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
