<?php

namespace App\Jobs;

use App\ClaudeOutputParser;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Models\Artifact;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ResearchYakJob implements ShouldQueue
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
        return [
            new EnsureDailyBudget,
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
            $this->ensureDefaultBranch($repository);

            $prompt = YakPromptBuilder::taskPrompt($this->task);
            $result = $this->invokeClaude($repository, $prompt);
            $parser = new ClaudeOutputParser($result);

            if ($parser->isError() || ! $parser->isValid()) {
                $this->handleError($parser->resultSummary() ?: 'Claude returned an error or malformed output');

                return;
            }

            $this->handleSuccess($repository, $parser);
        } catch (\Throwable $e) {
            Log::error('ResearchYakJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
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
        $summary = $parser->resultSummary();

        $artifact = $this->collectHtmlArtifact($repository);
        $artifactUrl = $artifact !== null ? $this->generateSignedUrl($artifact) : null;

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $summary,
            'cost_usd' => $parser->costUsd(),
            'num_turns' => $parser->numTurns(),
            'duration_ms' => $parser->durationMs(),
            'session_id' => $parser->sessionId(),
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        DailyCost::accumulate($parser->costUsd());

        $notificationMessage = $artifactUrl !== null
            ? "{$summary}\n\nFindings: {$artifactUrl}"
            : $summary;

        $this->postToSource($notificationMessage);

        if ($this->task->source === 'linear') {
            $this->moveLinearToDone();
        }
    }

    private function collectHtmlArtifact(Repository $repository): ?Artifact
    {
        $artifactPath = $repository->path.'/.yak-artifacts/research.html';

        if (! File::exists($artifactPath)) {
            return null;
        }

        return Artifact::create([
            'yak_task_id' => $this->task->id,
            'type' => 'research',
            'filename' => 'research.html',
            'disk_path' => $artifactPath,
            'size_bytes' => File::size($artifactPath),
        ]);
    }

    private function generateSignedUrl(Artifact $artifact): string
    {
        $expires = now()->addDays(7)->timestamp;
        $payload = "{$artifact->id}:{$expires}";
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return url("/artifacts/{$artifact->id}?expires={$expires}&signature={$signature}");
    }

    private function handleError(string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    private function postToSource(string $message): void
    {
        match ($this->task->source) {
            'slack' => $this->postToSlack($message),
            'linear' => $this->postToLinear($message),
            default => null,
        };
    }

    private function postToSlack(string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $this->task->slack_channel) {
            return;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $this->task->slack_channel,
                'thread_ts' => $this->task->slack_thread_ts,
                'text' => $message,
            ]);
    }

    private function postToLinear(string $message): void
    {
        $apiKey = (string) config('yak.channels.linear.api_key');

        if ($apiKey === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post('https://api.linear.app/graphql', [
                'query' => 'mutation($issueId: String!, $body: String!) { commentCreate(input: { issueId: $issueId, body: $body }) { success } }',
                'variables' => [
                    'issueId' => $this->task->external_id,
                    'body' => $message,
                ],
            ]);
    }

    private function moveLinearToDone(): void
    {
        $apiKey = (string) config('yak.channels.linear.api_key');

        if ($apiKey === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post('https://api.linear.app/graphql', [
                'query' => 'mutation($issueId: String!) { issueUpdate(id: $issueId, input: { stateId: "done" }) { success } }',
                'variables' => [
                    'issueId' => $this->task->external_id,
                ],
            ]);
    }
}
