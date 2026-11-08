<?php

namespace App\Jobs\Concerns;

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Exceptions\SandboxCapacityReached;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskAttachmentStager;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\Log;

/**
 * The before-turn half of a job that runs on a thread: wake the thread's
 * sandbox, let the job prepare the working tree, stage attachments and write
 * the turn. The job ends after this; the thread driver reports the turn's end
 * and DispatchAfterTurnWork queues the job's finisher.
 *
 * The consuming job must expose a `$task` property, use InteractsWithQueue
 * and define handleError(string).
 *
 * @property YakTask $task
 */
trait StartsThreadTurns
{
    /**
     * Returns whether the turn was written. $promptFor runs after $prepareTree,
     * so it can read what the preparation found.
     *
     * @param  callable(): string  $promptFor
     * @param  callable(IncusSandboxManager, string, Thread): void  $prepareTree
     * @param  iterable<TaskAttachment>  $attachments
     */
    protected function startThreadTurn(
        Repository $repository,
        TaskRunKind $kind,
        callable $promptFor,
        callable $prepareTree,
        iterable $attachments = [],
        bool $startsNewSession = false,
        TaskStatus $statusWhileWaiting = TaskStatus::Pending,
    ): bool {
        try {
            $thread = app(ThreadFactory::class)->forTask($this->task);
            $container = app(ThreadSandbox::class)->ensureAwake($thread, $repository);
            $sandbox = app(IncusSandboxManager::class);

            $prepareTree($sandbox, $container, $thread);
            $prompt = $promptFor();

            $staged = (new TaskAttachmentStager($sandbox))->stage($container, collect($attachments)->all());

            if ($staged !== '') {
                $prompt = rtrim($prompt) . "\n\n" . $staged;
            }

            $run = app(ThreadTurns::class)->start(
                $this->task,
                $kind,
                $prompt,
                YakPromptBuilder::systemPrompt($this->task),
                $this->task->started_by_user_id,
                $container,
                startsNewSession: $startsNewSession,
            );

            if ($run === null) {
                $this->handleError('The thread is blocked: ' . ($thread->refresh()->blocked_reason->value ?? 'no usable Claude account') . '.');

                return false;
            }

            return true;
        } catch (SandboxCapacityReached) {
            // Every awake sandbox is busy: wait for a slot instead of failing the task.
            $this->task->update(['status' => $statusWhileWaiting]);
            $this->release(60);

            return false;
        } catch (\Throwable $e) {
            Log::error(class_basename($this) . ' could not start its turn', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());

            return false;
        }
    }
}
