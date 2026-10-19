<?php

namespace App\Http\Resources;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\RepositorySettings;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RepositoryConfigParser;
use App\Services\RepositoryRiskProfiles;
use App\Services\RiskProfileMatcher;
use App\Support\CodeOwners;
use App\Support\RiskMapTree;
use Illuminate\Support\Facades\Cache;

/**
 * Shapes the risk profile page. The docblock on {@see self::from()} is the
 * source of truth for the TypeScript `RiskProfilePage` type.
 *
 * The page is read-only: every GitHub call is a read, and nothing is stored
 * except the one-hour cache of the computed map. Area names, rationale,
 * evidence, unknowns, owners, pull request titles and paths come from the
 * repository and leave here as plain props for React to escape.
 *
 * @phpstan-type PullRequestRef array{number: int, title: string, url: string}
 * @phpstan-type ChangedIn array{sha: string, url: ?string, authorName: string, date: string, pullRequest: PullRequestRef|null}
 * @phpstan-type MapPayload array{available: true, totalFiles: int, coveredFiles: int, uncheckedFiles: int, tree: list<array<string, mixed>>, coverage: list<array{name: string, files: int, coveredFiles: int, uncheckedFiles: int, floorFiles: int}>, gaps: list<array{path: string, files: int, isAggregate: bool}>}|array{available: false, reason: 'truncated'|'too-large'|'unreachable'}
 * @phpstan-type MapBundle array{map: MapPayload, areaFiles: list<int>, areaPathFiles: list<list<int>>, areaOwners: list<list<string>>, uncheckedFiles: int, changedIn: ChangedIn|null}
 */
final class RiskProfilePageData
{
    private const PROFILE_PATH = '.yak/risk-profile.yml';

    private const DRAFT_BRANCH = 'yak/risk-profile';

    private const MAP_CACHE_SECONDS = 3600;

    /**
     * `map.tree`, `map.coverage` and `map.gaps` rows follow {@see RiskMapTree}.
     * An area's `isUnchecked` files and a map's `uncheckedFiles` count files
     * whose area, floor or owner check could not be evaluated.
     *
     * @return array{
     *     state: 'profile'|'empty'|'never-valid'|'unavailable',
     *     source: 'file'|'database'|null,
     *     readState: 'read'|'unreachable'|'unavailable',
     *     readAt: ?string,
     *     readError: ?string,
     *     commitSha: ?string,
     *     file: array{
     *         validCommitSha: ?string,
     *         error: ?string,
     *         errorCommitSha: ?string,
     *         errorCommitUrl: ?string,
     *         errorPullRequest: array{number: int, title: string, url: string}|null,
     *         blobUrl: ?string,
     *         editUrl: ?string,
     *         changedIn: ChangedIn|null,
     *     }|null,
     *     generation: array{
     *         status: 'running'|'waiting'|'failed',
     *         task: array{id: int, url: string, startedAt: ?string, completedAt: ?string, error: ?string}|null,
     *         pullRequest: array{number: int, title: string, url: string, areas: ?int, unknowns: ?int}|null,
     *     }|null,
     *     profile: array{
     *         gateMode: string,
     *         alwaysHeld: list<string>,
     *         riskCounts: array{critical: int, high: int, unknown: int, medium: int, low: int},
     *         unknowns: list<string>,
     *         areas: list<array{
     *             index: int,
     *             name: string,
     *             risk: string,
     *             rationale: string,
     *             evidence: list<string>,
     *             symbols: list<string>,
     *             paths: list<array{glob: string, files: ?int}>,
     *             files: ?int,
     *             owners: list<string>,
     *             matchesNoFile: bool,
     *         }>,
     *     }|null,
     *     map: array{available: true, totalFiles: int, coveredFiles: int, uncheckedFiles: int, tree: list<array<string, mixed>>, coverage: list<array{name: string, files: int, coveredFiles: int, uncheckedFiles: int, floorFiles: int}>, gaps: list<array{path: string, files: int, isAggregate: bool}>}
     *         | array{available: false, reason: 'truncated'|'too-large'|'unreachable'}
     *         | null,
     * }
     */
    public static function from(Repository $repository, ConfigSnapshot $snapshot): array
    {
        $empty = [
            'state' => 'unavailable',
            'source' => null,
            'readState' => 'unavailable',
            'readAt' => null,
            'readError' => null,
            'commitSha' => null,
            'file' => null,
            'generation' => null,
            'profile' => null,
            'map' => null,
        ];

        if ($snapshot->state === 'unavailable') {
            return $empty;
        }

        $file = $snapshot->file('risk-profile.yml');
        $hasValidFile = $file !== null && $file->validCommitSha !== null;
        $isNeverValid = $file !== null && $file->validCommitSha === null && $file->error !== null;
        $profile = $isNeverValid ? null : self::effectiveProfile($repository);

        $state = match (true) {
            $hasValidFile && $profile !== null => 'profile',
            $isNeverValid => 'never-valid',
            $profile !== null => 'profile',
            default => 'empty',
        };

        $result = [
            ...$empty,
            'state' => $state,
            'source' => $state === 'profile' ? ($hasValidFile ? 'file' : 'database') : null,
            'readState' => $snapshot->state === 'read' ? 'read' : 'unreachable',
            'readAt' => $snapshot->readAt?->toIso8601String(),
            'readError' => $snapshot->readError,
            'commitSha' => $snapshot->commitSha,
            'file' => $file === null ? null : self::file($repository, $file),
            'generation' => $state === 'profile' ? null : self::generation($repository),
        ];

        if ($state !== 'profile' || $profile === null) {
            return $result;
        }

        $bundle = $snapshot->commitSha === null ? null : self::mapBundle($repository, $profile, $snapshot->commitSha, $file !== null);
        $areas = array_values($profile['areas']);

        if ($result['file'] !== null && $bundle !== null) {
            $result['file']['changedIn'] = $bundle['changedIn'];
        }

        $result['profile'] = [
            'gateMode' => (new RepositorySettings($repository, $snapshot))->coOwnerGateMode(),
            'alwaysHeld' => array_values((array) config('yak.pr_review.config_floor_paths')),
            'riskCounts' => self::riskCounts($areas),
            'unknowns' => array_values(array_map('strval', $profile['unknowns'] ?? [])),
            'areas' => self::areas($areas, $bundle),
        ];
        $result['map'] = $bundle['map'] ?? null;

        return $result;
    }

    /**
     * The profile the co-owner gate judges against: the `.yak/risk-profile.yml`
     * file when one is valid, otherwise the approved profile stored in Yak.
     *
     * @return array<string, mixed>|null
     */
    public static function effectiveProfile(Repository $repository): ?array
    {
        return $repository->settings()->riskProfile()
            ?? app(RepositoryRiskProfiles::class)->activeFromDatabase($repository->slug);
    }

    /** The CODEOWNERS file at a commit, or an empty one when the repository has none. */
    public static function codeOwners(Repository $repository, string $sha): CodeOwners
    {
        $github = app(AppService::class);
        $installationId = (int) config('yak.channels.github.installation_id');

        foreach (CodeOwners::LOCATIONS as $location) {
            $content = $github->getFileContents($installationId, $repository->github_full_name, $location, $sha);

            if ($content !== null) {
                return CodeOwners::parse($content);
            }
        }

        return CodeOwners::empty();
    }

    /**
     * @return array{validCommitSha: ?string, error: ?string, errorCommitSha: ?string, errorCommitUrl: ?string, errorPullRequest: array{number: int, title: string, url: string}|null, blobUrl: ?string, editUrl: ?string, changedIn: null}
     */
    private static function file(Repository $repository, ConfigFile $file): array
    {
        $githubUrl = $repository->githubUrl();
        $branch = $repository->default_branch;

        return [
            'validCommitSha' => $file->validCommitSha,
            'error' => $file->error,
            'errorCommitSha' => $file->errorCommitSha,
            'errorCommitUrl' => $githubUrl !== null && $file->errorCommitSha !== null ? "{$githubUrl}/commit/{$file->errorCommitSha}" : null,
            'errorPullRequest' => $file->errorPullRequest,
            'blobUrl' => $githubUrl !== null ? "{$githubUrl}/blob/{$branch}/" . self::PROFILE_PATH : null,
            'editUrl' => $githubUrl !== null ? "{$githubUrl}/edit/{$branch}/" . self::PROFILE_PATH : null,
            'changedIn' => null,
        ];
    }

    /**
     * @return array{status: 'running'|'waiting'|'failed', task: array{id: int, url: string, startedAt: ?string, completedAt: ?string, error: ?string}|null, pullRequest: array{number: int, title: string, url: string, areas: ?int, unknowns: ?int}|null}|null
     */
    private static function generation(Repository $repository): ?array
    {
        $pullRequest = self::draftPullRequest($repository);

        if ($pullRequest !== null) {
            return ['status' => 'waiting', 'task' => null, 'pullRequest' => $pullRequest];
        }

        $task = YakTask::where('repo', $repository->slug)
            ->where('external_id', 'like', 'risk-profile-%')
            ->orderByDesc('id')
            ->first();

        if ($task === null) {
            return null;
        }

        $isRunning = in_array($task->status, [TaskStatus::Pending, TaskStatus::Running, TaskStatus::Retrying], true);

        if (! $isRunning && $task->status !== TaskStatus::Failed) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $task->error_log)), fn (string $line): bool => $line !== ''));

        return [
            'status' => $isRunning ? 'running' : 'failed',
            'task' => [
                'id' => $task->id,
                'url' => route('tasks.show', $task),
                'startedAt' => $task->started_at?->toIso8601String(),
                'completedAt' => $task->completed_at?->toIso8601String(),
                'error' => $isRunning || $lines === [] ? null : mb_substr(end($lines), 0, 300),
            ],
            'pullRequest' => null,
        ];
    }

    /**
     * @return array{number: int, title: string, url: string, areas: ?int, unknowns: ?int}|null
     */
    private static function draftPullRequest(Repository $repository): ?array
    {
        try {
            $github = app(AppService::class);
            $installationId = (int) config('yak.channels.github.installation_id');
            $pullRequest = $github->findOpenPullRequestForBranch($installationId, $repository->github_full_name, self::DRAFT_BRANCH);
        } catch (\Throwable) {
            return null;
        }

        if ($pullRequest === null) {
            return null;
        }

        $areas = null;
        $unknowns = null;

        try {
            $content = $github->getFileContents($installationId, $repository->github_full_name, self::PROFILE_PATH, self::DRAFT_BRANCH);
            $parsed = $content === null ? null : (new RepositoryConfigParser)->parse('risk-profile.yml', $content);

            if ($parsed !== null && $parsed['errors'] === [] && is_array($parsed['data'])) {
                $areas = count($parsed['data']['areas'] ?? []);
                $unknowns = count($parsed['data']['unknowns'] ?? []);
            }
        } catch (\Throwable) {
        }

        return [
            'number' => (int) $pullRequest['number'],
            'title' => (string) ($pullRequest['title'] ?? ''),
            'url' => (string) ($pullRequest['html_url'] ?? ''),
            'areas' => $areas,
            'unknowns' => $unknowns,
        ];
    }

    /**
     * The computed map and its per-area counts, cached per commit and profile
     * version. A map GitHub could not be reached for is never cached.
     *
     * @param  array<string, mixed>  $profile
     * @return MapBundle
     */
    private static function mapBundle(Repository $repository, array $profile, string $commitSha, bool $hasFile): array
    {
        $key = "risk-map:{$repository->id}:{$commitSha}:" . ($profile['version'] ?? '');
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var MapBundle $cached */
            return $cached;
        }

        $bundle = self::computeBundle($repository, $profile, $commitSha, $hasFile);

        if (($bundle['map']['reason'] ?? null) !== 'unreachable') {
            Cache::put($key, $bundle, self::MAP_CACHE_SECONDS);
        }

        return $bundle;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return MapBundle
     */
    private static function computeBundle(Repository $repository, array $profile, string $commitSha, bool $hasFile): array
    {
        $bundle = [
            'map' => ['available' => false, 'reason' => 'unreachable'],
            'areaFiles' => [],
            'areaPathFiles' => [],
            'areaOwners' => [],
            'uncheckedFiles' => 0,
            'changedIn' => $hasFile ? self::changedIn($repository, $commitSha) : null,
        ];

        try {
            $tree = app(AppService::class)->listTreePaths((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $commitSha);

            if ($tree === null) {
                return [...$bundle, 'map' => ['available' => false, 'reason' => 'truncated']];
            }

            $areas = array_values($profile['areas']);
            $result = app(RiskProfileMatcher::class)->classify(
                $tree,
                $areas,
                array_values((array) config('yak.pr_review.config_floor_paths')),
                self::codeOwners($repository, $commitSha),
                withGlobHits: true,
            );

            if ($result['isCapped']) {
                return [...$bundle, 'map' => ['available' => false, 'reason' => 'too-large']];
            }

            $built = RiskMapTree::build($result['files'], array_map(fn (array $area): int => count($area['paths']), $areas));
        } catch (\Throwable) {
            return $bundle;
        }

        return [
            ...$bundle,
            'map' => [
                'available' => true,
                'totalFiles' => $built['totalFiles'],
                'coveredFiles' => $built['coveredFiles'],
                'uncheckedFiles' => $built['uncheckedFiles'],
                'tree' => $built['tree'],
                'coverage' => $built['coverage'],
                'gaps' => $built['gaps'],
            ],
            'areaFiles' => $built['areaFiles'],
            'areaPathFiles' => $built['areaPathFiles'],
            'areaOwners' => $built['areaOwners'],
            'uncheckedFiles' => $built['uncheckedFiles'],
        ];
    }

    /**
     * @return ChangedIn|null
     */
    private static function changedIn(Repository $repository, string $commitSha): ?array
    {
        try {
            $github = app(AppService::class);
            $installationId = (int) config('yak.channels.github.installation_id');
            $commit = $github->latestCommitForPath($installationId, $repository->github_full_name, self::PROFILE_PATH, $commitSha);

            if ($commit === null) {
                return null;
            }

            $githubUrl = $repository->githubUrl();

            return [
                'sha' => $commit['sha'],
                'url' => $githubUrl !== null ? "{$githubUrl}/commit/{$commit['sha']}" : null,
                'authorName' => $commit['authorName'],
                'date' => $commit['date'],
                'pullRequest' => $github->findPullRequestForCommit($installationId, $repository->github_full_name, $commit['sha']),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array{critical: int, high: int, unknown: int, medium: int, low: int}
     */
    private static function riskCounts(array $areas): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'unknown' => 0, 'medium' => 0, 'low' => 0];

        foreach ($areas as $area) {
            if (isset($counts[$area['risk']])) {
                $counts[$area['risk']]++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  MapBundle|null  $bundle
     * @return list<array{index: int, name: string, risk: string, rationale: string, evidence: list<string>, symbols: list<string>, paths: list<array{glob: string, files: ?int}>, files: ?int, owners: list<string>, matchesNoFile: bool}>
     */
    private static function areas(array $areas, ?array $bundle): array
    {
        $mapped = $bundle !== null && $bundle['map']['available'] ? $bundle : null;
        $hasMap = $mapped !== null;
        $rows = [];

        foreach ($areas as $index => $area) {
            $pathFiles = $mapped !== null ? ($mapped['areaPathFiles'][$index] ?? []) : [];
            $paths = [];
            foreach (array_values($area['paths']) as $globIndex => $glob) {
                $paths[] = ['glob' => (string) $glob, 'files' => $hasMap ? ($pathFiles[$globIndex] ?? 0) : null];
            }

            $rows[] = [
                'index' => $index,
                'name' => (string) $area['name'],
                'risk' => (string) $area['risk'],
                'rationale' => (string) ($area['rationale'] ?? ''),
                'evidence' => array_values(array_map('strval', $area['evidence'] ?? [])),
                'symbols' => array_values(array_map('strval', $area['symbols'] ?? [])),
                'paths' => $paths,
                'files' => $mapped !== null ? ($mapped['areaFiles'][$index] ?? 0) : null,
                'owners' => $mapped !== null ? ($mapped['areaOwners'][$index] ?? []) : [],
                'matchesNoFile' => $mapped !== null && $mapped['uncheckedFiles'] === 0 && array_sum($pathFiles) === 0,
            ];
        }

        return $rows;
    }
}
