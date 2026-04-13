<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\GitOperations;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RunYakJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public YakTask $task,
    ) {
        $this->onQueue('yak-claude');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $repository = Repository::where('slug', $this->task->repo)->first();
        $repoPath = $repository !== null ? $repository->path : '';

        return [
            new EnsureDailyBudget,
            new CleanupDevEnvironment($repoPath),
        ];
    }

    public function handle(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        TaskLogger::info($this->task, 'Picked up by worker', ['attempt' => $this->task->attempts + 1]);

        try {
            $this->preflight($repository);
            $this->prepareBranch($repository);

            $prompt = $this->assemblePrompt();

            $result = $agent->run(new AgentRunRequest(
                prompt: $prompt,
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                workingDirectory: $repository->path,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
            ));

            if ($result->isError) {
                $this->handleError($repository, $result->resultSummary ?: 'Agent returned an error or malformed output');

                return;
            }

            TaskLogger::info($this->task, 'Assessment complete');

            if ($result->clarificationNeeded && $this->task->source === 'slack') {
                $this->handleClarification($result);

                return;
            }

            $this->handleSuccess($repository, $result);
        } catch (ClaudeAuthException $e) {
            Log::error('RunYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($repository, $e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunYakJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($repository, $e->getMessage());
        }
    }

    private function preflight(Repository $repository): void
    {
        Process::path($repository->path)->run('docker-compose stop');

        $devPorts = [8000, 5173, 3000];
        foreach ($devPorts as $port) {
            Process::run("lsof -ti:{$port} | xargs kill -9 2>/dev/null || true");
        }
    }

    private function prepareBranch(Repository $repository): void
    {
        $branchName = GitOperations::createBranch($repository, $this->task->external_id);

        $this->task->update(['branch_name' => $branchName]);
    }

    private function assemblePrompt(): string
    {
        $metadata = self::parseMetadata($this->task->context);

        return YakPromptBuilder::taskPrompt($this->task, $metadata);
    }

    /**
     * Parse task context as JSON metadata, returning empty array for plain text or null.
     *
     * @return array<string, mixed>
     */
    private static function parseMetadata(?string $context): array
    {
        if ($context === null || $context === '') {
            return [];
        }

        /** @var array<string, mixed>|null */
        $decoded = json_decode($context, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result): void
    {
        TaskMetricsAccumulator::applyFresh($this->task, $result);

        $this->task->update([
            'status' => TaskStatus::AwaitingCi,
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ]);

        DailyCost::accumulate($result->costUsd);

        if ($this->task->branch_name !== null) {
            GitOperations::pushBranch($repository, $this->task->branch_name);
            TaskLogger::info($this->task, 'Fix pushed', ['branch' => $this->task->branch_name]);
        }

        if ($repository->ci_system === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true);
        }
    }

    private function handleClarification(AgentRunResult $result): void
    {
        TaskMetricsAccumulator::applyFresh($this->task, $result);

        $this->task->update([
            'status' => TaskStatus::AwaitingClarification,
            'clarification_options' => $result->clarificationOptions,
            'clarification_expires_at' => now()->addDays((int) config('yak.clarification_ttl_days')),
        ]);

        DailyCost::accumulate($result->costUsd);

        TaskLogger::info($this->task, 'Clarification posted');
    }

    private function handleError(Repository $repository, string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);
        GitOperations::checkoutDefaultBranch($repository);
    }
}
