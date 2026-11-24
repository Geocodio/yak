<?php

namespace App\Console\Commands;

use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\YakTask;
use App\Services\Tasks\TaskActions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('yak:recover-stopped-tasks')]
#[Description('Open the pull request, re-run CI or retry for tasks Yak stopped itself, so nobody has to nudge them')]
class RecoverStoppedTasksCommand extends Command
{
    /**
     * Skips while a deploy is draining: a job queued now would run in a
     * container about to be recreated. One task failing to recover never
     * stops the sweep, and its next round tries again.
     */
    public function handle(TaskActions $taskActions): int
    {
        if (Cache::has(PausesDuringDrain::CACHE_KEY)) {
            $this->components->info('Drain in progress, skipping.');

            return self::SUCCESS;
        }

        // A follow-up that stopped before its turn runs again as a follow-up, never as a plain retry of the task.
        $followUps = YakTask::withoutVisibilityScope()->followUpsStoppedBeforeTurn()->get();
        $tasks = YakTask::withoutVisibilityScope()->recoverableAfterYakStop()->whereNotIn('id', $followUps->modelKeys())->get();
        $acted = 0;

        foreach ($followUps->concat($tasks) as $task) {
            try {
                $outcome = $followUps->contains($task) ? $taskActions->restartStoppedFollowUp($task) : $taskActions->recoverStoppedTask($task);
            } catch (Throwable $e) {
                report($e);
                $this->components->warn("Task #{$task->id} could not be recovered: {$e->getMessage()}");

                continue;
            }

            $this->components->info("Task #{$task->id}: {$outcome}");

            if (! in_array($outcome, [TaskActions::RECOVERY_OUTCOME_SKIPPED, TaskActions::RECOVERY_OUTCOME_WAITING], true)) {
                $acted++;
            }
        }

        $this->components->info("Recovered {$acted} of " . ($followUps->count() + $tasks->count()) . ' stopped task(s).');

        return self::SUCCESS;
    }
}
