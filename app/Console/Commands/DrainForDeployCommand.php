<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Signature('yak:drain {--wait=300 : Max seconds to wait for running tasks before forcing failure} {--poll=5 : Polling interval in seconds}')]
#[Description('Pause new task pickups and wait for Running tasks to finish before a container recreate')]
class DrainForDeployCommand extends Command
{
    /**
     * Called by Ansible before a container recreate. Sets the drain
     * cache flag (read by PausesDuringDrain middleware) so queue
     * workers stop picking up new agent jobs, then polls for
     * in-flight Running tasks to finish.
     *
     * After the wait budget is exhausted, still-Running tasks are
     * marked Failed with a "deploy interrupted" error, their sandboxes
     * torn down, and their source channels notified. That leaves the
     * system in a clean state for Ansible to proceed with the
     * container recreate.
     *
     * AwaitingCi / AwaitingClarification tasks don't hold a worker and
     * are left alone — they'll resume polling under the new container.
     */
    public function handle(IncusSandboxManager $sandbox): int
    {
        $waitSeconds = (int) $this->option('wait');
        $pollSeconds = max(1, (int) $this->option('poll'));

        // TTL comfortably outlasts the expected deploy window so the
        // flag doesn't accidentally expire mid-drain and let a worker
        // grab a new job.
        Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addSeconds($waitSeconds + 600));

        $this->components->info('Drain flag set — workers will pause new pickups.');

        $elapsed = 0;
        while ($elapsed < $waitSeconds) {
            $runningCount = YakTask::where('status', TaskStatus::Running)->count();

            if ($runningCount === 0) {
                $this->components->info('No Running tasks — drain complete.');

                return self::SUCCESS;
            }

            $this->components->info("Waiting on {$runningCount} Running task(s)... ({$elapsed}s / {$waitSeconds}s)");

            sleep($pollSeconds);
            $elapsed += $pollSeconds;
        }

        $stragglers = YakTask::where('status', TaskStatus::Running)->get();

        if ($stragglers->isEmpty()) {
            $this->components->info('All tasks finished during final poll.');

            return self::SUCCESS;
        }

        $this->components->warn("Forcing {$stragglers->count()} straggler(s) to Failed after {$waitSeconds}s wait.");

        foreach ($stragglers as $task) {
            $this->failStraggler($task, $sandbox, $waitSeconds);
        }

        return self::SUCCESS;
    }

    private function failStraggler(YakTask $task, IncusSandboxManager $sandbox, int $waitSeconds): void
    {
        $reason = "Deploy interrupted the task after {$waitSeconds}s wait. Retry it once the deploy is done.";

        TaskLogger::warning($task, 'Task interrupted by deploy drain', [
            'wait_seconds' => $waitSeconds,
        ]);

        $task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $reason,
            'completed_at' => now(),
        ]);

        try {
            $containerName = $sandbox->containerName($task);
            if ($sandbox->containerExists($containerName)) {
                $sandbox->destroy($containerName);
            }
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during drain', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($task->source !== 'system') {
            try {
                SendNotificationJob::dispatch($task, NotificationType::Error, $reason);
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Failed to dispatch drain notification', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
