<?php

namespace App\Jobs;

use App\DataTransferObjects\AgentRunResult;
use App\Jobs\Concerns\FinishesRetryRuns;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Models\Repository;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\RunRecorder;

/**
 * The work after a CI retry turn has ended.
 */
class FinishRetryTurnJob extends FinishThreadTurnJob
{
    use FinishesRetryRuns;
    use GuardsTerminalTaskStatus;
    use HandlesWrongRepository;
    use NotifiesSourceOfFailure;

    protected function finishTurn(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $container, RunRecorder $recorder): void
    {
        $this->finishRetryResult($repository, $result, $sandbox, $container, $recorder);
    }

    protected function reportFailure(string $message, ?\Throwable $cause = null): void
    {
        $this->handleError($message);
    }
}
