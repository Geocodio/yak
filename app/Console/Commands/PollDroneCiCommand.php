<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\DroneBuildScanner;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('yak:poll-drone-ci')]
#[Description('Poll Drone CI for awaiting_ci tasks (Drone has no outbound webhooks)')]
class PollDroneCiCommand extends Command
{
    public function handle(DroneBuildScanner $scanner): int
    {
        $droneRepoSlugs = Repository::where('ci_system', 'drone')
            ->pluck('slug')
            ->all();

        if ($droneRepoSlugs === []) {
            return self::SUCCESS;
        }

        $tasks = YakTask::where('status', TaskStatus::AwaitingCi)
            ->whereNotNull('branch_name')
            ->whereIn('repo', $droneRepoSlugs)
            ->get();

        foreach ($tasks as $task) {
            $repository = Repository::firstWhere('slug', $task->repo);

            if ($repository === null) {
                continue;
            }

            $notBefore = $task->updated_at ?? $task->created_at;

            if ($notBefore === null) {
                continue;
            }

            try {
                $result = $scanner->pollBranchStatus(
                    $repository,
                    (string) $task->branch_name,
                    $notBefore,
                );
            } catch (\Throwable $e) {
                Log::warning('Drone poll failed', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result === null) {
                continue;
            }

            TaskLogger::info($task, 'CI result polled from Drone', [
                'passed' => $result->passed,
                'build_id' => $result->externalId,
            ]);

            ProcessCIResultJob::dispatch($task, $result->passed, $result->output);
        }

        return self::SUCCESS;
    }
}
