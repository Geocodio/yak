<?php

namespace App\Jobs;

use App\ClaudeOutputParser;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Models\Repository;
use App\Models\YakTask;
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
            new Middleware\CleanupDevEnvironment($repoPath),
        ];
    }

    public function handle(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        $repository->update(['setup_status' => 'running']);

        try {
            $this->preflight($repository);
            $this->ensureDefaultBranch($repository);

            $prompt = YakPromptBuilder::setupPrompt($repository->name);
            $result = $this->invokeClaude($repository, $prompt);
            $parser = new ClaudeOutputParser($result);

            if ($parser->isError() || ! $parser->isValid()) {
                $this->handleError(
                    $repository,
                    $parser->resultSummary() ?: 'Claude returned an error or malformed output',
                );

                return;
            }

            $this->handleSuccess($repository, $parser);
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
        Process::path($repository->path)->run('docker-compose stop');

        $devPorts = [8000, 5173, 3000];
        foreach ($devPorts as $port) {
            Process::run("lsof -ti:{$port} | xargs kill -9 2>/dev/null || true");
        }
    }

    private function ensureDefaultBranch(Repository $repository): void
    {
        GitOperations::checkoutDefaultBranch($repository);

        Process::path($repository->path)
            ->run("git pull origin {$repository->default_branch}");
    }

    private function invokeClaude(Repository $repository, string $prompt): string
    {
        $maxTurns = config('yak.max_turns');
        $maxBudget = config('yak.max_budget_per_task');
        $model = config('yak.default_model');
        $mcpConfig = config('yak.mcp_config_path');

        $systemPrompt = YakPromptBuilder::systemPrompt($this->task);

        $command = sprintf(
            'claude -p %s --dangerously-skip-permissions --bare --output-format json --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
            escapeshellarg($prompt),
            escapeshellarg((string) $model),
            $maxTurns,
            number_format((float) $maxBudget, 2, '.', ''),
            escapeshellarg($systemPrompt),
        );

        if ($mcpConfig) {
            $command .= sprintf(' --mcp-config %s', escapeshellarg((string) $mcpConfig));
        }

        $result = Process::path($repository->path)
            ->timeout($this->timeout - 30)
            ->run($command);

        return $result->output();
    }

    private function handleSuccess(Repository $repository, ClaudeOutputParser $parser): void
    {
        $this->task->update([
            'status' => TaskStatus::Success,
            'session_id' => $parser->sessionId(),
            'result_summary' => $parser->resultSummary(),
            'cost_usd' => $parser->costUsd(),
            'num_turns' => $parser->numTurns(),
            'duration_ms' => $parser->durationMs(),
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        $repository->update(['setup_status' => 'ready']);
    }

    private function handleError(Repository $repository, string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        $repository->update(['setup_status' => 'failed']);
    }
}
