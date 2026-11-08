<?php

namespace App\Jobs;

use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\TurnResult;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The work after a turn has ended: the logic the CLI path runs after the
 * agent returns, run against the thread's own sandbox. The sandbox is woken
 * for the work and paused again afterwards. Each subclass supplies the logic
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

    abstract protected function finishTurn(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $container, RunRecorder $recorder): void;

    abstract protected function reportFailure(string $message, ?\Throwable $cause = null): void;

    /**
     * Runs in the job's final step, before the recorder closes.
     */
    protected function afterTurn(): void {}

    public function handle(IncusSandboxManager $sandbox, ThreadSandbox $threadSandbox): void
    {
        $run = TaskRun::query()->with(['task', 'thread'])->find($this->taskRunId);

        if ($run === null || $run->task === null || $run->thread === null) {
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

            $threadSandbox->holdingSandbox($run->thread, function () use ($run, $repository, $result, $sandbox, $threadSandbox, $recorder): void {
                $container = $threadSandbox->ensureAwake($run->thread, $repository);

                $this->finishTurn($repository, $result, $sandbox, $container, $recorder);
            });

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
            try {
                $threadSandbox->pause($run->thread->refresh());
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Could not pause the thread sandbox after the turn', ['thread_id' => $run->thread_id, 'error' => $e->getMessage()]);
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
}
