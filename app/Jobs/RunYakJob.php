<?php

namespace App\Jobs;

use App\ClaudeOutputParser;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Models\Repository;
use App\Models\YakTask;
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
            new CleanupDevEnvironment($repoPath),
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

        try {
            $this->preflight($repository);
            $this->prepareBranch($repository);

            $prompt = $this->assemblePrompt();
            $result = $this->invokeClaude($repository, $prompt);
            $parser = new ClaudeOutputParser($result);

            if ($parser->isError() || ! $parser->isValid()) {
                $this->handleError($repository, $parser->resultSummary() ?: 'Claude returned an error or malformed output');

                return;
            }

            if ($parser->isClarification() && $this->task->source === 'slack') {
                $this->handleClarification($parser);

                return;
            }

            $this->handleSuccess($repository, $parser);
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
        $defaultBranch = $repository->default_branch;
        $branchName = 'yak/'.$this->task->external_id;

        Process::path($repository->path)->run("git checkout {$defaultBranch}");
        Process::path($repository->path)->run('git pull');
        Process::path($repository->path)->run("git checkout -b {$branchName}");

        $this->task->update(['branch_name' => $branchName]);
    }

    private function assemblePrompt(): string
    {
        /** @var array<string, string> */
        $templates = [
            'slack' => "Fix the following issue reported via Slack:\n\n%s",
            'linear' => "Fix the following Linear issue:\n\n%s",
            'sentry' => "Fix the following Sentry error:\n\n%s",
            'flaky-test' => "Fix the following flaky test:\n\n%s",
            'manual' => "Complete the following task:\n\n%s",
        ];

        $source = $this->task->source;
        $template = $templates[$source] ?? $templates['manual'];
        $description = $this->task->description;

        $prompt = sprintf($template, $description);

        if ($this->task->context) {
            $prompt .= "\n\nAdditional context:\n".$this->task->context;
        }

        return $prompt;
    }

    private function invokeClaude(Repository $repository, string $prompt): string
    {
        $maxTurns = config('yak.max_turns');
        $maxBudget = config('yak.max_budget_per_task');
        $model = config('yak.default_model');
        $mcpConfig = config('yak.mcp_config_path');

        $yakPrompt = 'You are Yak, an autonomous coding agent. Complete the task, commit your changes using /commit, and push with /ship.';

        $command = sprintf(
            'claude -p %s --dangerously-skip-permissions --bare --output-format json --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
            escapeshellarg($prompt),
            escapeshellarg((string) $model),
            $maxTurns,
            number_format((float) $maxBudget, 2, '.', ''),
            escapeshellarg($yakPrompt),
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
            'cost_usd' => $parser->costUsd(),
            'num_turns' => $parser->numTurns(),
            'duration_ms' => $parser->durationMs(),
            'model_used' => config('yak.default_model'),
        ]);

        Process::path($repository->path)
            ->run("git push -u origin {$this->task->branch_name}");
    }

    private function handleClarification(ClaudeOutputParser $parser): void
    {
        $this->task->update([
            'status' => TaskStatus::AwaitingClarification,
            'session_id' => $parser->sessionId(),
            'clarification_options' => $parser->clarificationOptions(),
            'clarification_expires_at' => now()->addDays((int) config('yak.clarification_ttl_days')),
            'cost_usd' => $parser->costUsd(),
            'num_turns' => $parser->numTurns(),
            'duration_ms' => $parser->durationMs(),
        ]);
    }

    private function handleError(Repository $repository, string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        Process::path($repository->path)
            ->run("git checkout {$repository->default_branch}");
    }
}
