<?php

namespace App\Services\Threads\Repos;

use App\DataTransferObjects\TaskStop;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Jobs\RunFollowUpJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\Tasks\StopNotifier;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Database\Eloquent\Collection;

/**
 * Threads started while their repository was still being set up. The first
 * turn is not dispatched until setup finishes: a ready repository releases the
 * waiting threads, a failed one marks them so the conversation says why, and
 * re-running setup puts them back to waiting.
 */
class ThreadsAwaitingRepository
{
    public function __construct(
        private readonly AgentJobDispatcher $dispatcher,
        private readonly ThreadEventPublisher $events,
    ) {}

    /**
     * Applies a change of the repository's setup status to the threads that wait on it.
     */
    public function repositoryStatusChanged(Repository $repository): void
    {
        match ($repository->setup_status) {
            'ready' => $this->release($repository),
            'failed' => $this->markFailed($repository),
            default => $this->markWaiting($repository),
        };
    }

    /**
     * Starts the first turn of every thread that waited on this repository.
     */
    public function release(Repository $repository): void
    {
        $this->threads($repository, [ThreadBlockReason::WaitingForRepository, ThreadBlockReason::RepositorySetupFailed])->each(function (Thread $thread): void {
            $task = YakTask::query()->where('thread_id', $thread->id)->where('status', TaskStatus::Pending)->oldest('id')->first();

            $thread->forceFill(['blocked_reason' => null, 'blocked_at' => null])->save();
            $this->events->appendThreadState($thread->id);

            if ($task === null) {
                return;
            }

            $jobClass = $task->claimingJobClass();

            if ($jobClass === null) {
                RunFollowUpJob::dispatch($task)->afterCommit();

                return;
            }

            $this->dispatcher->dispatch($task, $jobClass);
        });
    }

    private function markFailed(Repository $repository): void
    {
        $this->threads($repository, [ThreadBlockReason::WaitingForRepository])->each(function (Thread $thread) use ($repository): void {
            $this->block($thread, ThreadBlockReason::RepositorySetupFailed);

            $task = YakTask::query()->where('thread_id', $thread->id)->where('status', TaskStatus::Pending)->oldest('id')->first();

            if ($task !== null) {
                $setupTask = $repository->setup_task_id === null ? null : YakTask::withoutGlobalScopes()->find($repository->setup_task_id);

                StopNotifier::notify($task, TaskStop::setupFailed($repository->slug, $setupTask?->error_log ?: 'Setup failed'));
            }
        });
    }

    private function markWaiting(Repository $repository): void
    {
        $this->threads($repository, [ThreadBlockReason::RepositorySetupFailed])->each(fn (Thread $thread) => $this->block($thread, ThreadBlockReason::WaitingForRepository));
    }

    private function block(Thread $thread, ThreadBlockReason $reason): void
    {
        $thread->forceFill(['blocked_reason' => $reason, 'blocked_at' => now()])->save();
        $this->events->appendThreadState($thread->id);
    }

    /**
     * @param  list<ThreadBlockReason>  $reasons
     * @return Collection<int, Thread>
     */
    private function threads(Repository $repository, array $reasons)
    {
        return Thread::query()->withoutGlobalScopes()->where('repo', $repository->slug)->whereIn('blocked_reason', $reasons)->get();
    }
}
