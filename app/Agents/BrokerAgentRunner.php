<?php

namespace App\Agents;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Enums\TaskRunKind;
use App\Models\TaskRun;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Telemetry\RunContext;
use App\Services\Threads\OneShotRuns;

/**
 * Runs PR review and repo setup through the broker as one-shot runs, on an
 * unattended account (the request's, else the repository's, else the global one).
 */
class BrokerAgentRunner implements AgentRunner
{
    public function __construct(
        private readonly OneShotRuns $runs,
        private readonly ClaudeAccountResolver $resolver,
    ) {}

    public function run(AgentRunRequest $request): AgentRunResult
    {
        $task = $request->task;
        $account = $request->account ?? ($task === null ? null : $this->resolver->systemAccountFor($task->repo));

        if ($task === null || $account === null) {
            return AgentRunResult::failure('No Claude account is available for this run. Add a system account in the account settings.', '{}');
        }

        $recorded = RunContext::currentRunId();
        $run = $recorded === null ? null : TaskRun::query()->find($recorded);
        $ownsRun = $run === null;

        // Telemetry off: the run still needs a row for its turn slot.
        $run ??= TaskRun::query()->create([
            'yak_task_id' => $task->id,
            'kind' => $task->mode === TaskMode::Review ? TaskRunKind::Review : TaskRunKind::Setup,
            'attempt_number' => max(1, (int) $task->attempts),
            'job_class' => self::class,
            'queue' => 'yak-claude',
            'repo' => $task->repo,
            'source' => $task->source,
            'mode' => $task->mode->value,
            'model' => $request->model,
            'started_at' => now(),
        ]);

        try {
            return $this->runs->run($request, $run, $account);
        } finally {
            if ($ownsRun) {
                $run->delete();
            }
        }
    }
}
