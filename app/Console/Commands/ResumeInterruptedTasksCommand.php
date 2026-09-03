<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('yak:resume-interrupted-tasks {--max-resumes=3 : How many times a task may be auto-resumed before it is left for a manual retry}')]
#[Description('Requeue tasks a deploy drain force-failed, so a deploy costs time, never work')]
class ResumeInterruptedTasksCommand extends Command
{
    /**
     * Run by Ansible after the container is healthy AND after `yak:resume`
     * has cleared the drain flag — resumed work must not be immediately
     * re-paused by a flag the previous step just lifted.
     *
     * Only tasks whose `claimed_job_class` is one of the four claiming jobs
     * (RunYakJob, ResearchYakJob, RunYakReviewJob, SetupYakJob) — see
     * AgentJobDispatcher::claimableJobClasses() — are faithfully
     * reconstructable from what's persisted on the row. DrainForDeployCommand
     * only stamps that promise into the failure message for tasks that
     * qualify (see failStraggler()); everything else — a follow-up
     * (RunFollowUpJob), a clarification reply (ClarificationReplyJob), or a
     * CI retry (RetryYakJob, via a `Retrying` task) — already carries an
     * accurate "needs a manual retry" message and is left alone here beyond
     * clearing the marker so it isn't reconsidered on the next deploy.
     *
     * Resume must not touch `tasks.attempts`: it gates CI retries against
     * `yak.max_attempts` (config/yak.php, default 2), and the claim this
     * re-dispatch triggers (App\Jobs\Concerns\ClaimsTask::claimTask())
     * unconditionally increments it. Decrementing by one here first cancels
     * that out, so a task's CI-retry budget is exactly what it would have
     * been without the deploy interruption. `deploy_resume_count` is the
     * counter that actually bounds how many times a task can be resumed.
     */
    public function handle(AgentJobDispatcher $dispatcher): int
    {
        if (Cache::has(PausesDuringDrain::CACHE_KEY)) {
            $this->components->info('Drain in progress — skipping.');

            return self::SUCCESS;
        }

        $maxResumes = (int) $this->option('max-resumes');

        $candidates = YakTask::query()
            ->where('status', TaskStatus::Failed)
            ->whereNotNull('interrupted_by_deploy_at')
            ->get();

        $resumed = 0;
        $left = 0;

        foreach ($candidates as $task) {
            $jobClass = $this->resumableJobClassFor($task);

            if ($jobClass === null) {
                // Not one of the four claiming jobs — the drain message
                // already told the operator this needs a manual retry.
                // Just clear the marker so it isn't reconsidered.
                $task->update(['interrupted_by_deploy_at' => null]);
                $left++;

                continue;
            }

            if ($task->deploy_resume_count >= $maxResumes) {
                TaskLogger::warning($task, 'Task exceeded deploy-resume budget — left for a manual retry', [
                    'deploy_resume_count' => $task->deploy_resume_count,
                    'max_resumes' => $maxResumes,
                ]);

                $task->update([
                    'interrupted_by_deploy_at' => null,
                    'error_log' => "Deploy interrupted this task {$task->deploy_resume_count} time(s) already — it needs a manual retry.",
                ]);
                $left++;

                continue;
            }

            $task->update([
                'status' => TaskStatus::Pending,
                'error_log' => null,
                'completed_at' => null,
                'interrupted_by_deploy_at' => null,
                // Cancels out the claim this dispatch is about to trigger,
                // so the resume itself never costs a CI retry.
                'attempts' => max(0, $task->attempts - 1),
                'deploy_resume_count' => $task->deploy_resume_count + 1,
            ]);

            TaskLogger::info($task, 'Task resumed after deploy interruption', [
                'job_class' => $jobClass,
                'deploy_resume_count' => $task->deploy_resume_count,
            ]);

            $dispatcher->dispatch($task, $jobClass);

            if ($task->source !== 'system') {
                SendNotificationJob::dispatch(
                    $task,
                    NotificationType::Retry,
                    'Deploy finished — resuming automatically.',
                );
            }

            $resumed++;
        }

        $this->components->info(
            "Resumed {$resumed} interrupted task(s), left {$left} for a manual retry (checked {$candidates->count()})."
        );

        return self::SUCCESS;
    }

    /**
     * @return class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob>|null
     */
    private function resumableJobClassFor(YakTask $task): ?string
    {
        $jobClass = $task->claimed_job_class;

        if ($jobClass === null || ! in_array($jobClass, AgentJobDispatcher::claimableJobClasses(), true)) {
            return null;
        }

        /** @var class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob> $jobClass */
        return $jobClass;
    }
}
