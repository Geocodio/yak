<?php

namespace App\Services\Threads;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\DataTransferObjects\TaskStop;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\DefaultBranchCi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The GitHub checks behind a thread that waits on or stopped at CI: each check
 * with its result, whether a failure also happens on the default branch, and
 * Claude's diagnosis of the failure. Null when the thread has nothing to show.
 *
 * @phpstan-type CiCheck array{name: string, status: 'failed'|'running'|'passed'|'skipped', url: ?string, startedAt: ?string, durationSeconds: ?int, title: ?string, alsoFailsOnDefaultBranch: ?bool}
 * @phpstan-type CiChecks array{
 *     state: 'running'|'failed',
 *     pullRequestUrl: ?string,
 *     defaultBranch: string,
 *     counts: array{failing: int, passed: int, skipped: int, running: int},
 *     checks: list<CiCheck>,
 *     sharedWithDefaultBranch: bool,
 *     defaultBranchWait: ?array{since: string, limitHours: int},
 *     diagnosis: ?array{why: string, next: string},
 *     canRerun: bool,
 * }
 */
final class ThreadCiChecks
{
    private const BRANCH_CACHE_SECONDS = 15;

    private const DEFAULT_BRANCH_CACHE_SECONDS = 60;

    private const PASSING_CONCLUSIONS = ['success', 'neutral'];

    /**
     * @return CiChecks|null
     */
    public static function for(Thread $thread, ?User $viewer): ?array
    {
        if ($thread->is_legacy || ! $thread->isOpen()) {
            return null;
        }

        $task = ThreadTasks::head($thread);

        if ($task === null || $task->branch_name === null || ! in_array($task->status, [TaskStatus::AwaitingCi, TaskStatus::Failed, TaskStatus::Success], true)) {
            return null;
        }

        // A run that stopped for another reason keeps its own stop card, whatever its checks say.
        if ($task->status === TaskStatus::Failed && ! TaskStop::isCiStop($task->stop_summary, $task->error_log)) {
            return null;
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $repository = Repository::query()->where('slug', $task->repo)->first();

        if ($installationId === 0 || $repository === null || $repository->settings()->ciSystem() !== 'github_actions') {
            return null;
        }

        try {
            $runs = self::branchRuns($installationId, $repository, $task);
        } catch (Throwable $unreadable) {
            Log::channel('yak')->warning('Could not read the CI checks of a thread', ['thread_id' => $thread->id, 'error' => $unreadable->getMessage()]);

            return null;
        }

        $isRunning = collect($runs)->contains(fn (array $run): bool => $run['status'] !== 'completed');
        $hasFailure = collect($runs)->contains(fn (array $run): bool => self::resultOf($run) === 'failed');

        if ($runs === [] || (! $isRunning && ! $hasFailure)) {
            return null;
        }

        $state = $isRunning ? 'running' : 'failed';
        $defaultFailures = $state === 'failed' ? self::defaultBranchFailures($installationId, $repository) : null;
        $checks = [];

        foreach ($runs as $run) {
            $result = self::resultOf($run);
            $checks[] = [
                'name' => $run['name'],
                'status' => $result,
                'url' => $run['url'],
                'startedAt' => $run['startedAt'],
                'durationSeconds' => $run['startedAt'] !== null && $run['completedAt'] !== null ? max(0, (int) strtotime($run['completedAt']) - (int) strtotime($run['startedAt'])) : null,
                'title' => $run['title'],
                'alsoFailsOnDefaultBranch' => $result === 'failed' && $defaultFailures !== null ? in_array($run['name'], $defaultFailures, true) : null,
            ];
        }

        $order = ['failed' => 0, 'running' => 1, 'passed' => 2, 'skipped' => 3];
        usort($checks, fn (array $a, array $b): int => $order[$a['status']] <=> $order[$b['status']]);

        $failing = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'failed'));

        return [
            'state' => $state,
            'pullRequestUrl' => $task->pr_url,
            'defaultBranch' => $repository->default_branch,
            'counts' => [
                'failing' => count($failing),
                'passed' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'passed')),
                'skipped' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'skipped')),
                'running' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'running')),
            ],
            'checks' => $checks,
            'sharedWithDefaultBranch' => $state === 'failed' && $failing !== [] && collect($failing)->every(fn (array $check): bool => $check['alsoFailsOnDefaultBranch'] === true),
            'defaultBranchWait' => $state === 'failed' && $task->waiting_on_default_branch_at !== null
                ? ['since' => $task->waiting_on_default_branch_at->toIso8601String(), 'limitHours' => DefaultBranchCi::WAIT_LIMIT_HOURS]
                : null,
            'diagnosis' => $state === 'failed' ? $task->ci_diagnosis : null,
            'canRerun' => $state === 'failed' && app(ThreadAccess::class)->canDrive($viewer, $thread),
        ];
    }

    /**
     * Drops the cached read of the thread's branch, so the next page shows checks as GitHub has them now.
     */
    public static function forgetBranch(Thread $thread): void
    {
        $task = ThreadTasks::head($thread);

        if ($task?->branch_name !== null) {
            Cache::forget(self::cacheKey(Repository::githubNameFor($task->repo), $task->branch_name));
        }
    }

    /**
     * Stores a short fingerprint of what the CI card of the thread shows, the same for every viewer.
     * True when it differs from the one stored before, so open pages need to reload.
     */
    public static function rememberFingerprint(Thread $thread): bool
    {
        $checks = self::for($thread, null);
        $fingerprint = $checks === null ? 'none' : substr(md5(json_encode([
            $checks['state'],
            array_map(fn (array $check): array => [$check['name'], $check['status']], $checks['checks']),
        ], JSON_THROW_ON_ERROR)), 0, 12);

        if (Cache::get(self::fingerprintKey($thread->id)) === $fingerprint) {
            return false;
        }

        Cache::put(self::fingerprintKey($thread->id), $fingerprint, now()->addDay());

        return true;
    }

    /**
     * The fingerprint {@see rememberFingerprint()} stored last, or null before the first read.
     */
    public static function fingerprint(int $threadId): ?string
    {
        return Cache::get(self::fingerprintKey($threadId));
    }

    private static function fingerprintKey(int $threadId): string
    {
        return "thread-ci-checks-fingerprint:{$threadId}";
    }

    /**
     * @return list<array{name: string, status: ?string, conclusion: ?string, url: ?string, startedAt: ?string, completedAt: ?string, title: ?string}>
     */
    private static function branchRuns(int $installationId, Repository $repository, YakTask $task): array
    {
        $slug = $repository->github_full_name;
        $branch = (string) $task->branch_name;

        return Cache::remember(
            self::cacheKey($slug, $branch),
            self::BRANCH_CACHE_SECONDS,
            fn (): array => app(GitHubAppService::class)->listCheckRuns($installationId, $slug, $branch, isTimeBounded: true),
        );
    }

    /**
     * The names of the checks that fail on the default branch; null when it cannot be read.
     *
     * @return list<string>|null
     */
    private static function defaultBranchFailures(int $installationId, Repository $repository): ?array
    {
        try {
            $runs = Cache::remember(
                self::cacheKey($repository->github_full_name, $repository->default_branch),
                self::DEFAULT_BRANCH_CACHE_SECONDS,
                fn (): array => app(GitHubAppService::class)->listCheckRuns($installationId, $repository->github_full_name, $repository->default_branch, isTimeBounded: true),
            );
        } catch (Throwable $unreadable) {
            Log::channel('yak')->warning('Could not read the CI checks of the default branch', ['repo' => $repository->slug, 'error' => $unreadable->getMessage()]);

            return null;
        }

        return array_values(array_map(
            fn (array $run): string => $run['name'],
            array_filter($runs, fn (array $run): bool => self::resultOf($run) === 'failed'),
        ));
    }

    /**
     * @param  array{status: ?string, conclusion: ?string}  $run
     * @return 'failed'|'running'|'passed'|'skipped'
     */
    private static function resultOf(array $run): string
    {
        return match (true) {
            $run['status'] !== 'completed' => 'running',
            in_array($run['conclusion'], self::PASSING_CONCLUSIONS, true) => 'passed',
            $run['conclusion'] === 'skipped' => 'skipped',
            default => 'failed',
        };
    }

    private static function cacheKey(string $slug, string $ref): string
    {
        return 'thread-ci-checks:' . strtolower($slug) . ':' . $ref;
    }
}
