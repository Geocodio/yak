<?php

namespace App\Jobs\AfterTurn;

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\ThreadSandboxState;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\IncusThreadSandboxManager;
use App\Services\Threads\ThreadDiffCapture;
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

        $this->task = $run->task;
        TaskContext::set($this->task);
        $recorder = RunRecorder::forTurn($run);

        try {
            if ($this->taskIsTerminal($this->task)) {
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

                return;
            }

            $container = (string) $thread->refresh()->sandbox_container;
            $this->refreshGitCredentials($sandboxes, $thread);
            $this->finishTurn($repository, $result, ThreadShell::for($thread), $container, $recorder);

            // A wrong-repository verdict sends the task back to intake; its next run starts a thread in the right repo.
            if ($this->task->refresh()->repo === 'unknown') {
                $this->task->forceFill(['thread_id' => null])->save();
            }
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

            try {
                $threadSandbox->pauseIfIdle($thread->refresh());
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Could not pause the thread sandbox after the turn', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
            }

            $this->afterTurn();
            $recorder->finish();
            TaskContext::clear();
        }
    }

    public function failed(?\Throwable $e): void
    {
        $task = TaskRun::query()->find($this->taskRunId)?->task;

        if ($task === null) {
            return;
        }

        $this->task = $task;
        $this->reportFailure($e?->getMessage() ?? 'Job failed without exception');
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
    private function refreshGitCredentials(SandboxManager $sandboxes, Thread $thread): void
    {
        if ($sandboxes instanceof IncusThreadSandboxManager) {
            app(IncusSandboxManager::class)->injectGitCredentials($sandboxes->containerName($thread));
        }
    }
}
