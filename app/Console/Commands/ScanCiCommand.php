<?php

namespace App\Console\Commands;

use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Drone\BuildScanner as DroneBuildScanner;
use App\Channels\GitHub\ActionsBuildScanner as GitHubActionsBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\DataTransferObjects\ExistingFixPrMatch;
use App\DataTransferObjects\PullRequestCandidate;
use App\Enums\TaskMode;
use App\Jobs\RunYakJob;
use App\Models\FlakyTestClaim;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\ExistingFixPrFinder;
use App\Services\ObservationRecorder;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('yak:scan-ci {--repo=} {--dry-run : Report detected failures without creating tasks or dispatching jobs}')]
#[Description('Scan CI logs for flaky tests and create fix tasks')]
class ScanCiCommand extends Command
{
    private const SOURCE = 'flaky-test';

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

        // Only failures on the repo's default branch (main/master) qualify.
        // Failures on feature branches are the PR author's problem, not a flake —
        // chasing them spams the repo with fix PRs for one-off CI hiccups.
        $grouped = $failures->groupBy(
            fn (CIBuildFailure $f): string => CIBuildFailure::normalizeTestName($f->testName),
        );

        $candidates = $this->qualifyingFailures($repository, $grouped);

        if ($candidates->isEmpty()) {
            return 0;
        }

        $claimed = FlakyTestClaim::liveClassesFor($repository->slug);
        $openPullRequests = $dryRun
            ? collect()
            : app(ExistingFixPrFinder::class)->forRepository($repository);

        $tasksCreated = 0;

        // One task per commit, not per test: a single breakage usually fails
        // several tests at once, and one task per test means several agents
        // opening near-identical pull requests for the same root cause.
        foreach ($candidates->groupBy(fn (array $c): string => $c['commit_sha']) as $commitSha => $group) {
            /** @var Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}> $group */
            $tests = $this->filterAlreadyHandled($repository, $group, $claimed, $openPullRequests);

            if ($tests->isEmpty()) {
                continue;
            }

            if ($dryRun) {
                $names = $tests->map(fn (array $t): string => $t['failure']->testName)->implode(', ');
                $this->components->info("Would create task for commit {$commitSha}: {$names}");
                $tasksCreated++;

                continue;
            }

            if ($this->createTaskForCommit($repository, (string) $commitSha, $tests)) {
                $tasksCreated++;
            }
        }

        return $tasksCreated;
    }

    /**
     * Test failures that cleared the flaky threshold, each paired with the
     * most recent default-branch failure that qualified it.
     *
     * @param  Collection<string, Collection<int, CIBuildFailure>>  $grouped
     * @return Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}>
     */
    private function qualifyingFailures(Repository $repository, Collection $grouped): Collection
    {
        $candidates = collect();

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

                ObservationRecorder::declined(
                    source: self::SOURCE,
                    kind: 'flaky_test.below_threshold',
                    summary: "{$testName} failed {$occurrences->count()} time(s) but never on {$repository->default_branch}, so it is not treated as flaky.",
                    repo: $repository->slug,
                    subject: CIBuildFailure::normalizeTestClass((string) $testName),
                    metadata: [
                        'test_name' => $testName,
                        'failure_count' => $occurrences->count(),
                        'distinct_commits' => $commitCount,
                    ],
                );

                continue;
            }

            // Use the most recent default-branch failure as the canonical
            // representative — that's the failure that qualified the task.
            $canonical = $occurrences
                ->filter(fn (CIBuildFailure $f): bool => $f->branch === $repository->default_branch)
                ->sortByDesc('buildId')
                ->first();

            if ($canonical === null || ($canonical->commitSha ?? '') === '') {
                continue;
            }

            $candidates->push([
                'failure' => $canonical,
                'occurrences' => $occurrences,
                'commit_sha' => (string) $canonical->commitSha,
            ]);
        }

        return $this->collapseByTestClass($candidates);
    }

    /**
     * Pest truncates test names to the terminal width, so one test can arrive
     * under several names within a single scan. Grouping is by name, so those
     * become separate candidates; collapsing by class merges them back into
     * one, keeping the newest build and the union of the occurrences.
     *
     * @param  Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}>  $candidates
     * @return Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}>
     */
    private function collapseByTestClass(Collection $candidates): Collection
    {
        return $candidates
            ->groupBy(fn (array $c): string => $c['failure']->testClass())
            ->map(function (Collection $group): array {
                /** @var Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}> $group */
                /** @var array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string} $newest */
                $newest = $group->sortByDesc(fn (array $c): string => $c['failure']->buildId)->first();

                return [
                    'failure' => $newest['failure'],
                    'occurrences' => $group->flatMap(fn (array $c): Collection => $c['occurrences'])->values(),
                    'commit_sha' => $newest['commit_sha'],
                ];
            })
            ->values();
    }

    /**
     * Drop the tests Yak has already dealt with: one it is still working on,
     * and one a pull request already fixes.
     *
     * @param  Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}>  $group
     * @param  array<string, FlakyTestClaim>  $claimed
     * @param  Collection<int, PullRequestCandidate>  $openPullRequests
     * @return Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}>
     */
    private function filterAlreadyHandled(
        Repository $repository,
        Collection $group,
        array $claimed,
        Collection $openPullRequests,
    ): Collection {
        $finder = app(ExistingFixPrFinder::class);

        return $group->filter(function (array $candidate) use ($repository, $claimed, $openPullRequests, $finder): bool {
            $failure = $candidate['failure'];
            $testClass = $failure->testClass();

            if (isset($claimed[$testClass])) {
                $this->components->warn("Skipping {$failure->testName} — already handled.");

                $claim = $claimed[$testClass];

                ObservationRecorder::declined(
                    source: self::SOURCE,
                    kind: 'flaky_test.already_claimed',
                    summary: "{$testClass} is still failing, but Yak is already on it.",
                    repo: $repository->slug,
                    subject: $testClass,
                    referenceUrl: $claim->skipped_pr_url,
                    task: $claim->task,
                    metadata: ['test_name' => $failure->testName],
                );

                return false;
            }

            $match = $finder->match($openPullRequests, $testClass, $failure->testClassWasTruncated());

            if ($match instanceof ExistingFixPrMatch) {
                $this->components->warn(
                    "Skipping {$failure->testName} — PR #{$match->pullRequest->number} already fixes it ({$match->reason})."
                );

                $this->recordExistingPrSkip($repository, $failure, $testClass, $match);

                return false;
            }

            return true;
        })->values();
    }

    private function recordExistingPrSkip(
        Repository $repository,
        CIBuildFailure $failure,
        string $testClass,
        ExistingFixPrMatch $match,
    ): void {
        $state = $match->pullRequest->isMerged ? 'was merged' : 'is open';

        FlakyTestClaim::create([
            'repo' => $repository->slug,
            'test_class' => $testClass,
            'skipped_pr_url' => $match->pullRequest->url,
            'created_at' => now(),
        ]);

        ObservationRecorder::declined(
            source: self::SOURCE,
            kind: 'flaky_test.existing_pr',
            summary: "{$testClass} is failing on {$repository->default_branch}, but PR #{$match->pullRequest->number} {$state} and {$match->reason}.",
            repo: $repository->slug,
            subject: $testClass,
            referenceUrl: $match->pullRequest->url,
            metadata: [
                'test_name' => $failure->testName,
                'pr_number' => $match->pullRequest->number,
                'pr_title' => $match->pullRequest->title,
                'pr_merged' => $match->pullRequest->isMerged,
                'match_reason' => $match->reason,
                'build_url' => $failure->buildUrl,
            ],
        );
    }

    /**
     * @param  Collection<int, array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string}>  $tests
     */
    private function createTaskForCommit(Repository $repository, string $commitSha, Collection $tests): bool
    {
        /** @var array{failure: CIBuildFailure, occurrences: Collection<int, CIBuildFailure>, commit_sha: string} $first */
        $first = $tests->first();
        $canonical = $first['failure'];

        $description = $tests->count() === 1
            ? "Fix flaky test: {$canonical->testName}"
            : "Fix {$tests->count()} tests failing at {$repository->default_branch} " . substr($commitSha, 0, 7);

        try {
            $task = DB::transaction(function () use ($repository, $commitSha, $tests, $canonical, $description): ?YakTask {
                // Re-check inside the transaction: a concurrent pass may have
                // claimed these tests since the snapshot was taken.
                $stillLive = FlakyTestClaim::liveClassesFor($repository->slug);

                $remaining = $tests->reject(
                    fn (array $t): bool => isset($stillLive[$t['failure']->testClass()]),
                )->values();

                if ($remaining->isEmpty()) {
                    return null;
                }

                $task = YakTask::create([
                    'repo' => $repository->slug,
                    'external_id' => CIBuildFailure::commitExternalId($repository->slug, $commitSha),
                    'external_url' => $canonical->buildUrl,
                    'mode' => TaskMode::Fix,
                    'description' => $description,
                    'context' => json_encode([
                        'tests' => $remaining->map(fn (array $t): array => [
                            'test_name' => $t['failure']->testName,
                            'test_class' => $t['failure']->testClass(),
                            'failure_output' => $t['failure']->output,
                            'failure_count' => $t['occurrences']->count(),
                            'build_urls' => $t['occurrences']->pluck('buildUrl')->unique()->values()->all(),
                            'distinct_commits' => $t['occurrences']->pluck('commitSha')->filter()->unique()->values()->all(),
                        ])->all(),
                        'commit_sha' => $commitSha,
                        'build_url' => $canonical->buildUrl,
                        'build_id' => $canonical->buildId,
                    ]),
                    'source' => self::SOURCE,
                ]);

                foreach ($remaining as $test) {
                    FlakyTestClaim::create([
                        'repo' => $repository->slug,
                        'test_class' => $test['failure']->testClass(),
                        'yak_task_id' => $task->id,
                        'created_at' => now(),
                    ]);
                }

                return $task;
            });
        } catch (\Throwable $e) {
            // An overlapping scan pass losing a race must not abort the
            // remaining repositories.
            Log::warning('Failed to create flaky-test task', [
                'repo' => $repository->slug,
                'commit_sha' => $commitSha,
                'error' => $e->getMessage(),
            ]);
            $this->components->error("Failed to create task for commit {$commitSha}: {$e->getMessage()}");

            return false;
        }

        if ($task === null) {
            return false;
        }

        $testNames = $tests->map(fn (array $t): string => $t['failure']->testClass())->unique()->values()->all();

        TaskLogger::info($task, 'Task created', ['source' => self::SOURCE, 'repo' => $repository->slug]);

        ObservationRecorder::acted(
            source: self::SOURCE,
            kind: 'flaky_test.task_created',
            summary: $tests->count() === 1
                ? "{$canonical->testClass()} is failing on {$repository->default_branch}; opened task #{$task->id}."
                : "{$tests->count()} tests are failing at one commit on {$repository->default_branch}; opened task #{$task->id} covering all of them.",
            repo: $repository->slug,
            subject: $canonical->testClass(),
            referenceUrl: $canonical->buildUrl,
            task: $task,
            metadata: [
                'commit_sha' => $commitSha,
                'test_classes' => $testNames,
            ],
        );

        app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);

        $this->components->info("Created task #{$task->id} for " . implode(', ', $testNames));

        return true;
    }

    /**
     * A test crosses the flaky threshold only when it has failed at least once
     * on the repo's default branch. Feature-branch failures are excluded so we
     * don't open fix PRs for one-off CI hiccups on someone's in-flight work.
     *
     * @param  Collection<int, CIBuildFailure>  $occurrences
     */
    private function meetsFlakyThreshold(Repository $repository, Collection $occurrences): bool
    {
        $defaultBranch = $repository->default_branch;

        return $occurrences->contains(
            fn (CIBuildFailure $f): bool => $f->branch === $defaultBranch,
        );
    }

    private function resolveScanner(Repository $repository): ?CIBuildScanner
    {
        return match ($repository->ci_system) {
            'github_actions' => app(GitHubActionsBuildScanner::class),
            'drone' => app(DroneBuildScanner::class),
            default => null,
        };
    }
}
