<?php

namespace App\Jobs\AfterTurn;

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Push\LiveActivities\LiveActivities;
use App\Services\Telemetry\RunRecorder;
use App\Services\Teleport\EnsurePushedBranch;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\IncusThreadSandboxManager;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use App\Services\Threads\QueuedMessageRunner;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use App\Services\Threads\ThreadDiffCapture;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadShell;
use App\Services\Threads\TurnResult;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The work after a turn has ended: what a job did after the agent returned,
 * run in the thread's own sandbox through a SandboxShell. The sandbox is woken
 * for the work and paused again once idle. Each subclass supplies the logic
 * of the job whose turn it finishes.
 */
abstract class FinishThreadTurnJob implements ShouldQueue
{
    use GuardsTerminalTaskStatus;
    use Queueable;

    public int $timeout = 1800;

    public YakTask $task;

    public function __construct(public int $taskRunId)
    {
        $this->onQueue('yak-claude');
    }

    abstract protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void;

    abstract protected function reportFailure(string $message, ?\Throwable $cause = null): void;

    /**
     * Whether the job still runs when the head task already ended.
     */
    protected function runsOnTerminalTask(): bool
    {
        return false;
    }

    /**
     * Runs in the job's final step, before the recorder closes.
     */
    protected function afterTurn(): void {}

    public function handle(ThreadSandbox $threadSandbox, SandboxManager $sandboxes): void
    {
        $run = TaskRun::query()->with(['task', 'thread'])->find($this->taskRunId);
        $thread = $run?->thread;

        if ($run === null || $run->task === null || $thread === null) {
            return;
        }

        // A task that moved to another thread while the turn ran is no longer this turn's to finish.
        if ($run->task->thread_id !== null && $run->task->thread_id !== $thread->id) {
            return;
        }

        $this->task = $run->task;
        TaskContext::set($this->task);
        $recorder = RunRecorder::forTurn($run);
        $isReleased = false;
        $movesToUnknownRepository = false;

        try {
            if ($this->taskIsTerminal($this->task) && ! $this->runsOnTerminalTask()) {
                return;
            }

            $repository = Repository::where('slug', $this->task->repo)->first();

            if ($repository === null) {
                $this->reportFailure("Repository '{$this->task->repo}' not found or not configured in Yak.");

                return;
            }

            $result = TurnResult::toAgentRunResult($run);
            $recorder->recordOutcome($result);

            // A destroyed or paused sandbox is woken for the work; when it cannot be, the job waits instead of failing the task.
            if (! $threadSandbox->ensureAwake($thread)->ready) {
                $this->release(60);
                $isReleased = true;

                return;
            }

            $container = (string) $thread->refresh()->sandbox_container;
            $this->refreshGitCredentials($sandboxes, $threadSandbox, $thread);
            $this->finishTurn($repository, $result, ThreadShell::for($thread), $container, $recorder);

            $movesToUnknownRepository = $thread->hasRepository() && $this->task->refresh()->repo === Thread::UNKNOWN_REPOSITORY;
        } catch (ClaudeAuthException $e) {
            $recorder->failed($e, 'claude_auth');
            $this->reportFailure($e->getMessage());
        } catch (\Throwable $e) {
            Log::error(class_basename($this) . ' failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);

            $recorder->failed($e);
            $this->reportFailure($e->getMessage(), $e);
        } finally {
            // The sandbox is awake and its tree final here; once paused, the listener's capture would be skipped.
            $this->captureDiff($thread->refresh());

            // The turn may have added or changed .yak/preview.yml, so the cached dev server state is stale.
            app(ThreadPreviewDevServer::class)->forgetState($thread);

            if (! $isReleased && ! $this->task->targets_external_pr && $run->refresh()->getAttribute('turn_state') === TurnState::Completed) {
                $this->pushUnpushedCommits($thread);
            }

            try {
                $threadSandbox->pauseIfIdle($thread->refresh());
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Could not pause the thread sandbox after the turn', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }

            $this->afterTurn();

            // A wrong-repository verdict sends the task back to intake: its page continues in a thread that waits for a repository.
            if ($movesToUnknownRepository) {
                try {
                    app(ThreadFactory::class)->moved($thread->refresh(), $this->task, Thread::UNKNOWN_REPOSITORY);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            // A released job has not done its sandbox work yet, so its retry starts the queued turn instead.
            if (! $isReleased) {
                try {
                    app(LiveActivities::class)->turnSettled($run);
                } catch (\Throwable $e) {
                    report($e);
                }

                try {
                    app(QueuedMessageRunner::class)->afterTurn($run->refresh());
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $recorder->finish();
            TaskContext::clear();
        }
    }

    public function failed(?\Throwable $e): void
    {
        $run = TaskRun::query()->find($this->taskRunId);
        $task = $run?->task;

        if ($run === null || $task === null) {
            return;
        }

        $this->task = $task;
        $this->reportFailure($e?->getMessage() ?? 'Job failed without exception');

        // The finisher's own finally block never ran, so the activity ends here.
        try {
            app(LiveActivities::class)->turnSettled($run);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Whatever finisher ran, commits the turn left on the thread's branch go to the remote, so a later Open PR finds the branch.
     */
    private function pushUnpushedCommits(Thread $thread): void
    {
        try {
            app(EnsurePushedBranch::class)->pushPrimaryIfBehind($thread);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Could not push the thread branch after the turn', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
        }
    }

    private function captureDiff(Thread $thread): void
    {
        if ($thread->sandbox_state === ThreadSandboxState::Running) {
            app(ThreadDiffCapture::class)->capture($thread);
        }
    }

    /**
     * The credential helper baked into the sandbox may have expired during a
     * long turn, which would surface as a 401 on push.
     */
    private function refreshGitCredentials(SandboxManager $sandboxes, ThreadSandbox $threadSandbox, Thread $thread): void
    {
        if (! $sandboxes instanceof IncusThreadSandboxManager) {
            return;
        }

        $credentials = app(ThreadGitHubCredentials::class);

        try {
            $credentials->refresh($thread);

            return;
        } catch (\Throwable) {
            // The sandbox may have been paused behind the turn; wake it and try once more.
        }

        try {
            if ($threadSandbox->ensureAwake($thread->refresh())->ready) {
                $credentials->refresh($thread);
            }
        } catch (\Throwable $e) {
            // The token already in the sandbox may still work; the push reports its own failure if it does not.
            Log::warning('Refreshing the sandbox GitHub token failed after the turn', ['thread' => $thread->id, 'error' => $e->getMessage()]);
        }
    }
}
