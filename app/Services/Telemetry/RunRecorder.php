<?php

namespace App\Services\Telemetry;

use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskRunKind;
use App\Enums\TaskRunOutcome;
use App\Facades\Telemetry;
use App\Models\TaskRun;
use App\Models\YakTask;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records one task_runs row over the life of an agent job. The row is
 * created at start (so a worker that dies mid-run still leaves a trace
 * with no finished_at), stage durations accumulate through mark(), the
 * agent's own numbers land through agentFinished(), and finish() writes
 * everything in one update from the job's `finally`.
 *
 * Every method is a no-op when telemetry is off or the initial insert
 * failed; a recorder can never make a job fail.
 */
final class RunRecorder
{
    private const array STAGE_COLUMNS = [
        'sandbox_create' => 'sandbox_create_ms',
        'git_prepare' => 'git_prepare_ms',
        'agent' => 'agent_ms',
        'post_agent' => 'post_agent_ms',
        'teardown' => 'teardown_ms',
    ];

    private readonly int $startedAtNs;

    private int $lastMarkNs;

    /** @var array<string, int> */
    private array $stages = [];

    /** @var array<string, mixed> */
    private array $pending = [];

    private ?TaskRunOutcome $outcome = null;

    private bool $finished = false;

    private function __construct(private readonly ?TaskRun $run)
    {
        $this->startedAtNs = hrtime(true);
        $this->lastMarkNs = $this->startedAtNs;

        if ($run !== null) {
            RunContext::set($run->id);
        }
    }

    /**
     * @param  DateTimeInterface|null  $dispatchedAt  When the job that is now running was put on the queue, for queue_wait_ms.
     */
    public static function start(
        YakTask $task,
        TaskRunKind $kind,
        string $jobClass,
        ?DateTimeInterface $dispatchedAt = null,
        string $queue = 'yak-claude',
    ): self {
        if (! Telemetry::enabled()) {
            return new self(null);
        }

        try {
            $now = now();

            $run = TaskRun::create([
                'yak_task_id' => $task->id,
                'kind' => $kind,
                'attempt_number' => max(1, (int) $task->attempts),
                'job_class' => $jobClass,
                'queue' => $queue,
                'repo' => $task->repo,
                'source' => $task->source,
                'mode' => $task->mode->value,
                'model' => (string) config('yak.default_model'),
                'dispatched_at' => $dispatchedAt,
                'started_at' => $now,
                'queue_wait_ms' => $dispatchedAt !== null
                    ? max(0, $now->getTimestampMs() - (int) ($dispatchedAt->getTimestamp() * 1000))
                    : null,
            ]);

            return new self($run);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RunRecorder: failed to open run', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return new self(null);
        }
    }

    public function id(): ?int
    {
        return $this->run?->id;
    }

    /**
     * Close the stage that has been running since the previous mark (or
     * since start) and attribute its duration to `$stage`. Marking the
     * same stage twice adds up, so a job can mark around each push.
     */
    public function mark(string $stage): void
    {
        $now = hrtime(true);
        $this->stages[$stage] = ($this->stages[$stage] ?? 0) + (int) round(($now - $this->lastMarkNs) / 1_000_000);
        $this->lastMarkNs = $now;
    }

    /**
     * Attribute whatever ran after the agent (artifact collection, push,
     * status updates) to post_agent, unless the job already marked it.
     * Called from the job's `finally` before teardown so a push that threw
     * still lands in the right bucket.
     */
    public function closePostAgent(): void
    {
        if (isset($this->stages['agent']) && ! isset($this->stages['post_agent'])) {
            $this->mark('post_agent');
        }
    }

    /**
     * Called right before `$agent->run()`. Whatever ran since the last
     * mark is git preparation unless the job marked it otherwise.
     */
    public function agentStarted(AgentRunRequest $request): void
    {
        if (! isset($this->stages['git_prepare']) && isset($this->stages['sandbox_create'])) {
            $this->mark('git_prepare');
        }

        $this->pending['agent_started_at'] = now();
        $this->pending['model'] = $request->model;
        $this->pending['resumed'] = $request->isResume();
        $this->pending['prompt_chars'] = mb_strlen($request->prompt) + mb_strlen($request->systemPrompt);
    }

    /**
     * Called right after `$agent->run()` returns, whatever it returned.
     */
    public function agentFinished(AgentRunResult $result): void
    {
        $this->mark('agent');

        $this->pending['agent_finished_at'] = now();
        $this->pending['session_id'] = $result->sessionId !== '' ? $result->sessionId : null;
        $this->pending['cost_usd'] = $result->costUsd;
        $this->pending['num_turns'] = $result->numTurns;
        $this->pending['synthesized_result'] = $result->synthesized;
        $this->pending['stale_session_retry'] = $result->staleSessionRetry;
        $this->pending['permission_denials'] = $result->permissionDenials;

        if ($result->usage !== null) {
            $this->pending['input_tokens'] = $result->usage->inputTokens;
            $this->pending['output_tokens'] = $result->usage->outputTokens;
            $this->pending['cache_read_tokens'] = $result->usage->cacheReadTokens;
            $this->pending['cache_creation_tokens'] = $result->usage->cacheCreationTokens;
            $this->pending['agent_api_ms'] = $result->usage->apiDurationMs;
            $this->pending['model_usage'] = $result->usage->modelUsage === [] ? null : $result->usage->modelUsage;
        }

        $stats = $result->stats;
        if ($stats !== null) {
            $this->pending['tool_calls'] = $stats->toolCalls;
            $this->pending['tool_errors'] = $stats->toolErrors;
            $this->pending['tool_ms'] = $stats->toolMs;
            $this->pending['mcp_calls'] = $stats->mcpCalls;
            $this->pending['tool_breakdown'] = $stats->tools === [] ? null : $stats->tools;
            $this->pending['api_retries'] = $stats->apiRetries;
            $this->pending['api_retry_breakdown'] = $stats->apiRetryBreakdown === [] ? null : $stats->apiRetryBreakdown;
            $this->pending['assistant_messages'] = $stats->assistantMessages;
            $this->pending['forced_termination'] = $stats->forcedTermination;
            $this->pending['cli_version'] = $stats->cliVersion;
            $this->pending['resumed'] = ($this->pending['resumed'] ?? false) || $stats->resumedInPlace;

            if (($this->pending['model_usage'] ?? null) === null && $stats->usageByModel !== []) {
                $this->pending['model_usage'] = $stats->usageByModel;
            }
        }

        if ($result->isError) {
            $this->outcome = TaskRunOutcome::Error;
            $this->pending['error_subtype'] = $result->failureCategory();
            $this->pending['error_message'] = mb_substr($result->failureMessage(), 0, 2000);
        } elseif ($result->clarificationNeeded) {
            $this->outcome = TaskRunOutcome::Clarification;
        } else {
            $this->outcome = TaskRunOutcome::Success;
        }
    }

    /**
     * @param  array{commits: int, files: int, insertions: int, deletions: int}  $stats
     */
    public function gitStats(array $stats): void
    {
        $this->pending['commits'] = $stats['commits'];
        $this->pending['files_changed'] = $stats['files'];
        $this->pending['lines_added'] = $stats['insertions'];
        $this->pending['lines_removed'] = $stats['deletions'];
    }

    /**
     * The agent finished cleanly but produced no commits.
     */
    public function noChanges(): void
    {
        $this->outcome = TaskRunOutcome::NoChanges;
        $this->pending['commits'] = 0;
    }

    /**
     * Something outside the agent failed (sandbox, git, push, PR).
     * Keeps an agent-reported error if one was already recorded.
     */
    public function failed(Throwable|string $error, ?string $category = null): void
    {
        if ($this->outcome === TaskRunOutcome::Error) {
            return;
        }

        $this->outcome = TaskRunOutcome::Exception;
        $this->pending['error_subtype'] = $category ?? ($error instanceof Throwable ? class_basename($error) : 'exception');
        $this->pending['error_message'] = mb_substr($error instanceof Throwable ? $error->getMessage() : $error, 0, 2000);
    }

    /**
     * Persist everything. Safe to call more than once; only the first
     * call writes. Call from the job's `finally` after teardown.
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        RunContext::clear();

        if ($this->run === null) {
            return;
        }

        try {
            $totalMs = (int) round((hrtime(true) - $this->startedAtNs) / 1_000_000);

            $update = $this->pending + [
                'outcome' => $this->outcome ?? TaskRunOutcome::Exception,
                'finished_at' => now(),
                'total_ms' => $totalMs,
                'worker_peak_mb' => (int) round(memory_get_peak_usage(true) / 1024 / 1024),
            ];

            if ($this->outcome === null && ! isset($update['error_message'])) {
                $update['error_subtype'] = 'unknown';
                $update['error_message'] = 'Run ended without a recorded outcome';
            }

            $extraStages = $this->stages;
            foreach (self::STAGE_COLUMNS as $stage => $column) {
                if (isset($extraStages[$stage])) {
                    $update[$column] = $extraStages[$stage];
                    unset($extraStages[$stage]);
                }
            }
            $update['stages'] = $extraStages === [] ? null : $extraStages;

            $this->run->update($update);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RunRecorder: failed to close run', [
                'run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
