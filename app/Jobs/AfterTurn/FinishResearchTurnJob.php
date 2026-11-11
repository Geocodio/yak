<?php

namespace App\Jobs\AfterTurn;

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesResearchRuns;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ReportsResearchToSource;
use App\Models\Repository;
use App\Services\Telemetry\RunRecorder;

/**
 * The work after a research turn has ended.
 */
class FinishResearchTurnJob extends FinishThreadTurnJob
{
    use AsksClarifyingQuestions;
    use FinishesResearchRuns;
    use GuardsTerminalTaskStatus;
    use NotifiesSourceOfFailure;
    use ReportsResearchToSource;

    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $container, RunRecorder $recorder): void
    {
        // The answers are delivered once the agent has returned, so a turn that never ended keeps them.
        $this->consumeAnswers();

        $this->finishResearchResult($repository, $result, $sandbox, $container);
        $recorder->mark('post_agent');
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
