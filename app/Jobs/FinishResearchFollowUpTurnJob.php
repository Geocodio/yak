<?php

namespace App\Jobs;

use App\DataTransferObjects\AgentRunResult;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesResearchFollowUpRuns;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ReportsResearchToSource;
use App\Models\Repository;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\RunRecorder;

/**
 * The work after a research follow-up turn has ended.
 */
class FinishResearchFollowUpTurnJob extends FinishThreadTurnJob
{
    use AsksClarifyingQuestions;
    use FinishesResearchFollowUpRuns;
    use GuardsTerminalTaskStatus;
    use NotifiesSourceOfFailure;
    use ReportsResearchToSource;

    protected function finishTurn(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $container, RunRecorder $recorder): void
    {
        $this->finishResearchFollowUpResult($result, $sandbox, $container);
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
