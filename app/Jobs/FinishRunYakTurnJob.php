<?php

namespace App\Jobs;

use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesAgentRuns;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
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
 * The work after an initial or clarification turn has ended: the logic the
 * CLI path runs after the agent returns, run against the thread's own
 * sandbox. The sandbox is woken for the work and paused again afterwards.
 */
class FinishRunYakTurnJob implements ShouldQueue
{
    use AsksClarifyingQuestions;
    use FinishesAgentRuns;
    use HandlesWrongRepository;
    use NotifiesSourceOfFailure;
    use Queueable;

    public int $timeout = 1800;

    public YakTask $task;

    public ?string $staleSessionFallbackPrompt = null;

    public function __construct(public int $taskRunId)
    {
        $this->onQueue('yak-claude');
    }

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
                $this->handleError("Repository '{$this->task->repo}' not found or not configured in Yak.");

                return;
            }

            $result = TurnResult::toAgentRunResult($run);
            $recorder->recordOutcome($result);
            $container = $threadSandbox->ensureAwake($run->thread, $repository);

            $this->finishAgentResult($repository, $result, $sandbox, $container, $recorder);

            // A wrong-repository verdict sends the task back to intake; its next run starts a thread in the right repo.
            if ($this->task->refresh()->repo === 'unknown') {
                $this->task->forceFill(['thread_id' => null])->save();
            }
        } catch (ClaudeAuthException $e) {
            $recorder->failed($e, 'claude_auth');
            $this->handleError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('FinishRunYakTurnJob failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);

            $recorder->failed($e);
            $this->handleError($e->getMessage());
        } finally {
            try {
                $threadSandbox->pause($run->thread->refresh());
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Could not pause the thread sandbox after the turn', ['thread_id' => $run->thread_id, 'error' => $e->getMessage()]);
            }

            $this->finishParkingForQuestions();
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
        $this->handleError($e?->getMessage() ?? 'Job failed without exception');
    }
}
