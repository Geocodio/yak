<?php

namespace App\Console\Commands;

use App\Channels\Contracts\CIBuildScanner;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

#[Signature('yak:scan-ci {--repo=} {--dry-run : Report detected failures without creating tasks or dispatching jobs}')]
#[Description('Scan CI logs for flaky tests and create fix tasks')]
class ScanCiCommand extends Command
{
    public function handle(): int
    {
        /** @var string|null $repoSlug */
        $repoSlug = $this->option('repo');
        $dryRun = (bool) $this->option('dry-run');

        $repositories = $repoSlug !== null
            ? Repository::where('slug', $repoSlug)->where('is_active', true)->get()
            : Repository::where('is_active', true)->get();

        if ($repositories->isEmpty()) {
            $this->components->warn('No active repositories found.');

            return self::SUCCESS;
        }

        $tasksCreated = 0;

        foreach ($repositories as $repository) {
            $tasksCreated += $this->scanRepository($repository, $dryRun);
        }

        if ($dryRun) {
            $this->components->info("Dry run complete. Would have created {$tasksCreated} task(s).");
        } else {
            $this->components->info("Scan complete. Created {$tasksCreated} task(s).");
        }

        return self::SUCCESS;
    }

    private function scanRepository(Repository $repository, bool $dryRun = false): int
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

        // Group by normalized test name, then apply the flaky threshold:
        //   - default branch:      any single failure counts
        //   - other branches:      need >=2 failures from >=2 distinct commits
        // (Same-commit reruns are the PR author's problem, not a flake.)
        $grouped = $failures->groupBy(
            fn (CIBuildFailure $f): string => CIBuildFailure::normalizeTestName($f->testName),
        );

        foreach ($grouped as $testName => $occurrences) {
            /** @var Collection<int, CIBuildFailure> $occurrences */
            if ($testName === '') {
                continue;
            }

            if (! $this->meetsFlakyThreshold($repository, $occurrences)) {
                $commitCount = $occurrences->pluck('commitSha')->filter()->unique()->count();
                $this->components->info(
                    "Below flaky threshold: {$testName} ({$occurrences->count()} failure(s), {$commitCount} distinct commit(s))"
                );

                continue;
            }

            // Use the most recent failure as the canonical representative.
            $canonical = $occurrences->sortByDesc('buildId')->first();

            if ($canonical === null) {
                continue;
            }

            if ($this->isDuplicate($repository, $canonical)) {
                $this->components->warn("Skipping {$canonical->testName} — task already exists.");

                continue;
            }

            if ($dryRun) {
                $this->components->info(
                    "Would create task for: {$canonical->testName} (build {$canonical->buildId}, {$occurrences->count()} failure(s))"
                );
                $tasksCreated++;

                continue;
            }

            $task = YakTask::create([
                'repo' => $repository->slug,
                'external_id' => $canonical->externalId(),
                'external_url' => $canonical->buildUrl,
                'mode' => TaskMode::Fix,
                'description' => "Fix flaky test: {$canonical->testName}",
                'context' => json_encode([
                    'test_name' => $canonical->testName,
                    'failure_output' => $canonical->output,
                    'build_url' => $canonical->buildUrl,
                    'build_id' => $canonical->buildId,
                    'failure_count' => $occurrences->count(),
                    'distinct_commits' => $occurrences->pluck('commitSha')->filter()->unique()->values()->all(),
                    'build_urls' => $occurrences->pluck('buildUrl')->unique()->values()->all(),
                ]),
                'source' => 'flaky-test',
            ]);

            TaskLogger::info($task, 'Task created', ['source' => 'flaky-test', 'repo' => $repository->slug]);
            RunYakJob::dispatch($task);
            $tasksCreated++;

            $this->components->info("Created task #{$task->id} for flaky test: {$canonical->testName}");
        }

        return $tasksCreated;
    }

    /**
     * A test crosses the flaky threshold when it has either:
     *   - failed on the repo's default branch (any single failure counts), or
     *   - failed in >=2 builds from >=2 distinct commits on other branches.
     *
     * Same-commit failures on a feature branch are not flakes — they're a
     * broken PR that the author should fix.
     *
     * @param  Collection<int, CIBuildFailure>  $occurrences
     */
    private function meetsFlakyThreshold(Repository $repository, Collection $occurrences): bool
    {
        $defaultBranch = $repository->default_branch;

        if ($occurrences->contains(
            fn (CIBuildFailure $f): bool => $f->branch === $defaultBranch,
        )) {
            return true;
        }

        $distinctCommits = $occurrences->pluck('commitSha')->filter()->unique();

        return $distinctCommits->count() >= 2;
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
