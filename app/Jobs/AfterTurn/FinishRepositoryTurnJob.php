<?php

namespace App\Jobs\AfterTurn;

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Models\Repository;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\PullRequests\ThreadRepositoryPullRequests;
use Illuminate\Support\Facades\Log;

/**
 * The work after a turn started for an event on a sibling repo's pull request:
 * publishes the extra repos. The head task has usually succeeded already, so
 * this job runs on a terminal task and never writes its status.
 */
class FinishRepositoryTurnJob extends FinishThreadTurnJob
{
    protected function runsOnTerminalTask(): bool
    {
        return true;
    }

    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void
    {
        if ($result->isError) {
            Log::channel('yak')->warning('A repository event turn ended in error; nothing was published', ['task_id' => $this->task->id, 'error' => $result->resultSummary]);

            return;
        }

        app(ThreadRepositoryPullRequests::class)->publishForTask($this->task);
    }

    protected function reportFailure(string $message, ?\Throwable $cause = null): void
    {
        Log::channel('yak')->warning('A repository event turn finisher failed', ['task_id' => $this->task->id, 'error' => $message]);
    }
}
