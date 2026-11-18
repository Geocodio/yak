<?php

namespace App\Services\CiFailure;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\CIBuildFailure;
use App\DataTransferObjects\CiFailureQualification;
use App\Enums\TaskMode;
use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ResponsiblePersonResolver;
use App\Services\TaskLogger;
use Illuminate\Support\Collection;

/**
 * Builds the fix task for a qualified shared CI failure.
 */
class CiFailureTaskCreator
{
    public const SOURCE = 'ci-failure';

    public function __construct(private readonly AppService $appService) {}

    /**
     * Fetches everything the task needs from GitHub, so create() can run
     * under a lock without network calls.
     *
     * @return array{excerpts: array<int, string|null>, author: string|null}
     */
    public function gather(Repository $repository, CiFailureQualification $qualification): array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $lines = (int) config('yak.ci_failure.log_excerpt_lines');
        $excerpts = [];

        foreach ($qualification->evidence as $row) {
            $excerpts[$row->job_id] = $this->appService->getJobLogExcerpt($installationId, $repository->github_full_name, $row->job_id, $lines);
        }

        $failure = $qualification->evidence->first();
        $author = null;

        if ($failure !== null && $failure->branch === $repository->default_branch) {
            $author = $this->appService->findPullRequestForCommit($installationId, $repository->github_full_name, $failure->head_sha)['author'] ?? null;
        }

        return ['excerpts' => $excerpts, 'author' => $author];
    }

    /**
     * @param  array{excerpts: array<int, string|null>, author: string|null}  $gathered
     */
    public function create(Repository $repository, CiFailureQualification $qualification, array $gathered): ?YakTask
    {
        $evidence = $qualification->evidence;
        /** @var CiJobFailure|null $failure */
        $failure = $evidence->first();

        if ($failure === null) {
            return null;
        }

        $failures = [];
        $excerpts = [];

        foreach ($evidence as $row) {
            $excerpt = $gathered['excerpts'][$row->job_id] ?? null;
            $excerpts[] = (string) $excerpt;
            $failures[] = [
                'branch' => $row->branch,
                'head_sha' => $row->head_sha,
                'job_url' => $row->job_url,
                'failed_step' => $row->failed_step,
                'log_excerpt' => $excerpt,
            ];
        }

        $advisoryIds = $this->advisoryIds(implode("\n", $excerpts));
        $testNames = self::sharedTestNames($evidence);
        $greenAt = CiDefaultBranchGreen::latestFor($failure->repo, $failure->workflow_name, $failure->job_name);

        $context = [
            'workflow_path' => $failure->workflow_path,
            'workflow_name' => $failure->workflow_name,
            'job_name' => $failure->job_name,
            'failures' => $failures,
            'default_branch_last_green_at' => $greenAt?->toIso8601String(),
            'open_ci_failure_prs' => $this->openFixPullRequests($repository),
        ];

        if ($testNames !== []) {
            $context['test_names'] = $testNames;
        }

        if ($advisoryIds !== []) {
            $context['advisory_ids'] = $advisoryIds;
        }

        $oldest = $evidence->sortBy('failed_at')->firstOrFail();
        $newest = $evidence->sortByDesc('failed_at')->firstOrFail();

        $author = $gathered['author'];

        $resolver = app(ResponsiblePersonResolver::class);

        $task = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => 'ci-failure:' . md5("{$repository->slug}:{$failure->workflow_path}:{$failure->job_name}:{$oldest->job_id}"),
            'external_url' => $newest->job_url,
            'mode' => TaskMode::Fix,
            'description' => $this->description($repository, $failure, $evidence, $advisoryIds),
            'context' => json_encode($context),
            'source' => self::SOURCE,
            'responsible_name' => $resolver->resolve(null, $author, $repository->slug),
            'responsible_user_id' => $resolver->resolveUser(null, null, $repository->slug)?->id,
        ]);

        TaskLogger::info($task, 'Task created', ['source' => self::SOURCE, 'repo' => $repository->slug]);

        return $task;
    }

    /**
     * Test names that fail in more than one recorded failure, or the
     * triggering failure's own names when none are shared.
     *
     * @param  Collection<int, CiJobFailure>  $evidence
     * @return list<string>
     */
    public static function sharedTestNames(Collection $evidence): array
    {
        /** @var CiJobFailure $failure */
        $failure = $evidence->first();
        $own = array_values(array_unique(array_map(CIBuildFailure::normalizeTestName(...), (array) $failure->test_names)));
        $others = $evidence->skip(1)
            ->flatMap(fn (CiJobFailure $row): array => array_map(CIBuildFailure::normalizeTestName(...), (array) $row->test_names))
            ->all();
        $shared = array_values(array_intersect($own, $others));

        return $shared !== [] ? $shared : $own;
    }

    /**
     * @return list<string>
     */
    private function advisoryIds(string $text): array
    {
        preg_match_all('/GHSA(?:-[23456789cfghjmpqrvwx]{4}){3}|CVE-\d{4}-\d{4,}/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @return list<array{number: int|null, url: string|null, title: string}>
     */
    private function openFixPullRequests(Repository $repository): array
    {
        return array_values(YakTask::query()
            ->where('repo', $repository->slug)
            ->where('source', self::SOURCE)
            ->whereNotNull('pr_url')
            ->whereNull('pr_merged_at')
            ->whereNull('pr_closed_at')
            ->get()
            ->map(fn (YakTask $task): array => [
                'number' => $task->pullRequestNumber(),
                'url' => $task->pr_url,
                'title' => $task->description,
            ])
            ->all());
    }

    /**
     * @param  Collection<int, CiJobFailure>  $evidence
     * @param  list<string>  $advisoryIds
     */
    private function description(Repository $repository, CiJobFailure $failure, Collection $evidence, array $advisoryIds): string
    {
        if ($advisoryIds !== []) {
            return "Fix CI: {$failure->job_name} (" . implode(', ', $advisoryIds) . ')';
        }

        $branchCount = $evidence->pluck('branch')->unique()->count();

        return $branchCount > 1
            ? "Fix CI: {$failure->job_name} failing on {$branchCount} branches"
            : "Fix CI: {$failure->job_name} failing on {$repository->default_branch}";
    }
}
