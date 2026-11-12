<?php

namespace App\Jobs\Concerns;

use App\Contracts\SandboxShell;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\TaskAttachmentStager;
use App\Services\Threads\Repos\ThreadRepositoryPrompt;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadShell;
use App\Services\Threads\ThreadTurns;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\Log;

/**
 * The before-turn half of a job that runs on a thread: wake the thread's
 * sandbox, let the job prepare the working tree, stage attachments and write
 * the turn. The job ends after this; the thread driver reports the turn's end
 * and DispatchAfterTurnWork queues the job's finisher.
 *
 * The consuming job must expose a `$task` property, use Queueable and define
 * handleError(string).
 *
 * @property YakTask $task
 */
trait StartsThreadTurns
{
    use ReportsMissingAccount;

    /** Seconds a held job waits before it asks again whether a usage limit has passed. */
    private const int USAGE_LIMIT_RECHECK_SECONDS = 300;

    /**
     * Returns whether the turn was written. `$promptFor` runs after
     * `$prepareTree`, so it can read what the preparation found.
     *
     * @param  callable(Thread): string  $promptFor
     * @param  callable(SandboxShell, Thread): void  $prepareTree
     * @param  iterable<TaskAttachment>  $attachments
     */
    protected function startThreadTurn(
        Repository $repository,
        TaskRunKind $kind,
        callable $promptFor,
        callable $prepareTree,
        iterable $attachments = [],
        TaskStatus $statusWhileWaiting = TaskStatus::Pending,
    ): bool {
        try {
            $thread = app(ThreadFactory::class)->forTask($this->task);

            if (! app(ThreadSandbox::class)->ensureAwake($thread)->ready) {
                $this->task->update(['status' => $statusWhileWaiting]);
                $this->release(60);

                return false;
            }

            $shell = ThreadShell::for($thread);
            $prepareTree($shell, $thread);
            $prompt = $promptFor($thread);
            $setupFailure = ThreadRepositoryPrompt::setupFailureNotice($thread);

            if ($setupFailure !== null) {
                $prompt = rtrim($prompt) . "\n\n" . $setupFailure;
            }

            $staged = (new TaskAttachmentStager($shell))->stage((string) $thread->sandbox_container, collect($attachments)->all());

            if ($staged !== '') {
                $prompt = rtrim($prompt) . "\n\n" . $staged;
            }

            $thread->forceFill(['append_system_prompt' => trim(YakPromptBuilder::systemPrompt($this->task) . "\n\n" . ThreadRepositoryPrompt::section($thread))])->save();

            $driver = $this->task->started_by_user_id === null ? null : User::query()->find($this->task->started_by_user_id);
            $run = app(ThreadTurns::class)->start($this->task, $prompt, $driver, null);

            // The turn starter names a run by whether the thread has run before; the job knows which kind of turn it wrote.
            if ($run->kind !== $kind) {
                $run->forceFill(['kind' => $kind])->save();
            }

            return $this->holdWhileBlocked($thread->refresh(), $statusWhileWaiting);
        } catch (ThreadBusy) {
            // Another turn is starting or running on this thread: wait for it instead of working on the same tree.
            $this->task->update(['status' => $statusWhileWaiting]);
            $this->release(30);

            return false;
        } catch (\Throwable $e) {
            if ($this->isMissingAccountFailure($e)) {
                $this->handleError(self::MISSING_ACCOUNT_MESSAGE);

                return false;
            }

            Log::error(class_basename($this) . ' could not start its turn', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());

            return false;
        }
    }

    /**
     * A turn waiting for an account slot or a sandbox starts on its own, so
     * the job is done. A usage limit or an unusable account leaves the turn
     * unable to start: it is cancelled, and the job tries again later or
     * fails the task.
     */
    private function holdWhileBlocked(Thread $thread, TaskStatus $statusWhileWaiting): bool
    {
        $reason = $thread->blocked_reason;

        if (! in_array($reason, [ThreadBlockReason::UsageLimit, ThreadBlockReason::AccountInvalid, ThreadBlockReason::AccountExpired], true)) {
            return true;
        }

        app(ThreadTurns::class)->stop($thread);

        if ($reason === ThreadBlockReason::UsageLimit) {
            $this->task->update(['status' => $statusWhileWaiting]);
            $this->release(self::USAGE_LIMIT_RECHECK_SECONDS);

            return false;
        }

        $this->handleError("The thread's Claude account is not usable ({$reason->value}). Ask an admin to reconnect it.");

        return false;
    }
}
