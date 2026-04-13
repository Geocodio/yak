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
use App\Models\Artifact;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\YakPersonality;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

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

    public function handle(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        TaskLogger::info($this->task, 'Picked up by worker — research');

        try {
            $this->ensureDefaultBranch($repository);

            $result = $agent->run(new AgentRunRequest(
                prompt: YakPromptBuilder::taskPrompt($this->task),
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
                $this->handleError($result->resultSummary ?: 'Agent returned an error or malformed output');

                return;
            }

            $this->handleSuccess($repository, $result);
        } catch (ClaudeAuthException $e) {
            Log::error('ResearchYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
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

    private function handleSuccess(Repository $repository, AgentRunResult $result): void
    {
        $summary = $result->resultSummary;

        $artifact = $this->collectHtmlArtifact($repository);
        $artifactUrl = $artifact !== null ? $this->generateSignedUrl($artifact) : null;

        TaskMetricsAccumulator::applyFresh($this->task, $result);

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $summary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        DailyCost::accumulate($result->costUsd);

        TaskLogger::info($this->task, 'Task completed');

        $context = $artifactUrl !== null
            ? "Research complete: {$summary}\n\nFindings: {$artifactUrl}"
            : "Research complete: {$summary}";

        $notificationMessage = YakPersonality::generate(NotificationType::Result, $context);
        $this->postToSource($notificationMessage);

        if ($this->task->source === 'linear') {
            $this->moveLinearToDone();
        }
    }

    private function collectHtmlArtifact(Repository $repository): ?Artifact
    {
        $sourcePath = $repository->path . '/.yak-artifacts/research.html';

        if (! File::exists($sourcePath)) {
            return null;
        }

        $storagePath = "{$this->task->id}/research.html";

        Storage::disk('artifacts')->put($storagePath, File::get($sourcePath));

        return Artifact::create([
            'yak_task_id' => $this->task->id,
            'type' => 'research',
            'filename' => 'research.html',
            'disk_path' => $storagePath,
            'size_bytes' => File::size($sourcePath),
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
        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);

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
