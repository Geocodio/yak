<?php

namespace App\Console\Commands;

use App\Contracts\CIBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\Enums\TaskMode;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\DroneBuildScanner;
use App\Services\GitHubActionsBuildScanner;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('yak:scan-ci {--repo=}')]
#[Description('Scan CI logs for flaky tests and create fix tasks')]
class ScanCiCommand extends Command
{
    public function handle(): int
    {
        /** @var string|null $repoSlug */
        $repoSlug = $this->option('repo');

        $repositories = $repoSlug !== null
            ? Repository::where('slug', $repoSlug)->where('is_active', true)->get()
            : Repository::where('is_active', true)->get();

        if ($repositories->isEmpty()) {
            $this->components->warn('No active repositories found.');

            return self::SUCCESS;
        }

        $tasksCreated = 0;

        foreach ($repositories as $repository) {
            $tasksCreated += $this->scanRepository($repository);
        }

        $this->components->info("Scan complete. Created {$tasksCreated} task(s).");

        return self::SUCCESS;
    }

    private function scanRepository(Repository $repository): int
    {
        $scanner = $this->resolveScanner($repository);

        if (! $scanner) {
            $this->components->warn("No CI scanner available for {$repository->slug} (ci_system: {$repository->ci_system}).");

            return 0;
        }

        try {
            $maxAgeHours = (int) config('yak.ci_scan.max_failure_age_hours', 48);
            $failures = $scanner->getRecentFailures($repository, $maxAgeHours);
        } catch (\Throwable $e) {
            Log::warning("Failed to scan CI for {$repository->slug}", [
                'error' => $e->getMessage(),
            ]);
            $this->components->error("Failed to scan {$repository->slug}: {$e->getMessage()}");

            return 0;
        }

        $tasksCreated = 0;

        foreach ($failures as $failure) {
            if ($this->isDuplicate($repository, $failure)) {
                $this->components->warn("Skipping {$failure->testName} — task already exists.");

                continue;
            }

            $task = YakTask::create([
                'repo' => $repository->slug,
                'external_id' => $failure->externalId(),
                'external_url' => $failure->buildUrl,
                'mode' => TaskMode::Fix,
                'description' => "Fix flaky test: {$failure->testName}",
                'context' => json_encode([
                    'test_name' => $failure->testName,
                    'failure_output' => $failure->output,
                    'build_url' => $failure->buildUrl,
                    'build_id' => $failure->buildId,
                ]),
                'source' => 'flaky-test',
            ]);

            TaskLogger::info($task, 'Task created', ['source' => 'flaky-test', 'repo' => $repository->slug]);
            RunYakJob::dispatch($task);
            $tasksCreated++;

            $this->components->info("Created task #{$task->id} for flaky test: {$failure->testName}");
        }

        return $tasksCreated;
    }

    private function resolveScanner(Repository $repository): ?CIBuildScanner
    {
        return match ($repository->ci_system) {
            'github_actions' => app(GitHubActionsBuildScanner::class),
            'drone' => app(DroneBuildScanner::class),
            default => null,
        };
    }

    private function isDuplicate(Repository $repository, CIBuildFailure $failure): bool
    {
        return YakTask::where('repo', $repository->slug)
            ->where('external_id', $failure->externalId())
            ->exists();
    }
}
