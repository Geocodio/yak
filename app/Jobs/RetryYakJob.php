<?php

namespace App\Jobs;

use App\ClaudeOutputParser;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Models\Repository;
use App\Models\YakTask;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RetryYakJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly ?string $failureOutput = null,
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
            new CleanupDevEnvironment($repoPath),
        ];
    }

    public function handle(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        try {
            $this->preflight($repository);
            $this->checkoutTaskBranch($repository);

            $prompt = $this->buildRetryPrompt();
            $result = $this->invokeClaude($repository, $prompt);
            $parser = new ClaudeOutputParser($result);

            if ($parser->isError() || ! $parser->isValid()) {
                $this->handleError($repository, $parser->resultSummary() ?: 'Claude returned an error or malformed output');

                return;
            }

            $this->handleSuccess($repository, $parser);
        } catch (\Throwable $e) {
            Log::error('RetryYakJob failed', [
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

    private function checkoutTaskBranch(Repository $repository): void
    {
        $branchName = 'yak/'.$this->task->external_id;

        GitOperations::checkoutBranch($repository, $branchName);
    }

    private function buildRetryPrompt(): string
    {
        return YakPromptBuilder::retryPrompt($this->failureOutput);
    }

    private function invokeClaude(Repository $repository, string $prompt): string
    {
        $maxTurns = config('yak.max_turns');
        $maxBudget = config('yak.max_budget_per_task');
        $model = config('yak.default_model');
        $mcpConfig = config('yak.mcp_config_path');
        $sessionId = $this->task->session_id;

        $systemPrompt = YakPromptBuilder::systemPrompt($this->task);

        $command = sprintf(
            'claude -p %s --resume %s --dangerously-skip-permissions --bare --output-format json --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
            escapeshellarg($prompt),
            escapeshellarg((string) $sessionId),
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
            'status' => TaskStatus::AwaitingCi,
            'session_id' => $parser->sessionId(),
            'result_summary' => $parser->resultSummary(),
            'cost_usd' => (float) $this->task->cost_usd + $parser->costUsd(),
            'num_turns' => $this->task->num_turns + $parser->numTurns(),
            'duration_ms' => $this->task->duration_ms + $parser->durationMs(),
            'model_used' => config('yak.default_model'),
        ]);

        if ($this->task->branch_name !== null) {
            GitOperations::forcePushBranch($repository, $this->task->branch_name);
        }
    }

    private function handleError(Repository $repository, string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        GitOperations::checkoutDefaultBranch($repository);
    }
}
