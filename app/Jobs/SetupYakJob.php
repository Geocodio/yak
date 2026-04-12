<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\GitOperations;
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

class SetupYakJob implements ShouldQueue
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
            new Middleware\CleanupDevEnvironment($repoPath),
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

        TaskLogger::info($this->task, 'Picked up by worker — setup');
        $repository->update(['setup_status' => 'running']);

        try {
            $this->preflight($repository);
            $this->ensureDefaultBranch($repository);

            $result = $agent->run(new AgentRunRequest(
                prompt: YakPromptBuilder::setupPrompt($repository->name),
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
                $this->handleError(
                    $repository,
                    $result->resultSummary ?: 'Agent returned an error or malformed output',
                );

                return;
            }

            $this->handleSuccess($repository, $result);
        } catch (ClaudeAuthException $e) {
            Log::error('SetupYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($repository, $e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('SetupYakJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($repository, $e->getMessage());
        }
    }

    private function preflight(Repository $repository): void
    {
        if (! is_dir($repository->path)) {
            $this->cloneRepository($repository);
        }

        Process::path($repository->path)->run('docker-compose stop');

        $devPorts = [8000, 5173, 3000];
        foreach ($devPorts as $port) {
            Process::run("lsof -ti:{$port} | xargs kill -9 2>/dev/null || true");
        }
    }

    private function cloneRepository(Repository $repository): void
    {
        if (empty($repository->git_url)) {
            throw new \RuntimeException("Repository {$repository->slug} has no git_url configured.");
        }

        GitOperations::ensureCredentials();

        TaskLogger::info($this->task, "Cloning {$repository->git_url} to {$repository->path}");

        $result = Process::run("git clone {$repository->git_url} {$repository->path}");

        if (! $result->successful()) {
            throw new \RuntimeException("Failed to clone repository: {$result->errorOutput()}");
        }
    }

    private function ensureDefaultBranch(Repository $repository): void
    {
        GitOperations::checkoutDefaultBranch($repository);

        Process::path($repository->path)
            ->run("git pull origin {$repository->default_branch}");
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result): void
    {
        TaskMetricsAccumulator::applyFresh($this->task, $result);

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        DailyCost::accumulate($result->costUsd);

        TaskLogger::info($this->task, 'Task completed');
        $repository->update(['setup_status' => 'ready']);
    }

    private function handleError(Repository $repository, string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);
        $repository->update(['setup_status' => 'failed']);
    }
}
