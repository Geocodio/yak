<?php

namespace App\Http\Resources;

use App\Channels\Sentry\Service as SentryService;
use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerGateStatus;
use App\Services\RepositoryConfig;
use App\Services\RepositoryRiskProfiles;
use App\Support\Docs;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Shapes the sectioned repository settings pages. The docblocks below are the
 * source of truth for the TypeScript types in `resources/js/types/repositorySettings.ts`.
 *
 * Every section page receives `header` ({@see self::header()}) and the deferred
 * `yakConfig` ({@see YakConfigData}, group `yak-config`), plus the props of its
 * own section:
 *
 * - general: `{repository: {slug, name, description, agentInstructions, gitUrl, path, defaultBranch, publicSiteUrl, isActive, isDefault, defaultResponsibleUserId, ciSystem, sentryProject, prReviewEnabled, githubFullName, githubUrl, githubNameDiverged}, options: {ciSystems, sentryProjects, users, defaultPathExcludes, defaultReviewPolicy}, sandbox: {snapshot, baseVersion, latestBaseVersion}, docsLinks, shadowDays: int, concernsPullRequest (deferred, group concerns-pull-request): ?{number, url}}`
 * - review: `{repository: {slug, defaultBranch, prReviewEnabled, reviewPolicy: {profile_max_age_days}, riskProfiles, riskProfileActionUrl, pathExcludes}, options, docsLinks, coOwnerGate (deferred, group co-owner-gate)}`
 * - previews: `{repository: {slug, deploymentsEnabled, previewRepairEnabled}, manifest: {port, healthProbePath, coldStart, checkoutRefresh, wakeTimeoutSeconds}, runningPreviews: int, docsLinks}`
 * - sandbox: `{repository: {slug}, sandbox: {snapshot, baseVersion, latestBaseVersion}, setupHistory: list<{status, id, url, startedAgo, duration}>, stats: {tasks, tasks7d, reviews30d}, docsLinks}`
 * - accounts: `{repository: {slug}, claudeAccounts: RepositoryClaudeAccountData}`
 * - danger: `{repository: {slug, name, isActive}, canDelete: bool, deleteBlockedReason: ?string}`
 *
 * `options.users`, `ciSystems` and `sentryProjects` are `{value, label}` lists;
 * `riskProfiles` is the shape of {@see RepositoryRiskProfiles::forSettings()}.
 */
final class RepositorySettingsData
{
    /** @var array<string, string> */
    public const SECTIONS = [
        'general' => 'General',
        'review' => 'PR review',
        'approvals' => 'Auto-approval',
        'previews' => 'Previews',
        'sandbox' => 'Sandbox',
        'accounts' => 'Accounts',
        'danger' => 'Danger zone',
    ];

    /** Sections served by `repos.settings.show`. */
    public const SETTINGS_SECTIONS = ['review', 'previews', 'sandbox', 'accounts', 'danger'];

    /**
     * @return array{slug: string, name: string, githubUrl: ?string, isActive: bool, prReviewEnabled: bool, deploymentsEnabled: bool, approval: array{level: int, mode: 'off'|'shadow'|'enforce'}, sections: array<int, array{slug: string, label: string, url: string}>}
     */
    public static function header(Repository $repository): array
    {
        return [
            'slug' => $repository->slug,
            'name' => $repository->name,
            'githubUrl' => $repository->githubUrl(),
            'isActive' => (bool) $repository->is_active,
            'prReviewEnabled' => (bool) $repository->pr_review_enabled,
            'deploymentsEnabled' => (bool) $repository->deployments_enabled,
            'approval' => [
                'level' => $repository->approvalLevel(),
                'mode' => $repository->approvalMode(),
            ],
            'sections' => collect(self::SECTIONS)
                ->map(fn (string $label, string $slug): array => [
                    'slug' => $slug,
                    'label' => $label,
                    'url' => self::sectionUrl($repository, $slug),
                ])
                ->values()
                ->all(),
        ];
    }

    public static function sectionUrl(Repository $repository, string $section): string
    {
        return match ($section) {
            'general' => route('repos.edit', $repository),
            'approvals' => route('repos.approvals.show', $repository),
            default => route('repos.settings.show', ['repository' => $repository, 'section' => $section]),
        };
    }

    /**
     * The deferred `.yak/` file strip shown on every section.
     *
     * @return array<string, mixed>
     */
    public static function yakConfigProp(Repository $repository): array
    {
        return [
            'yakConfig' => Inertia::defer(
                fn () => YakConfigData::from($repository, app(RepositoryConfig::class)->snapshot($repository)),
                'yak-config',
            ),
        ];
    }

    /**
     * Props of one section page, including `header` and the deferred `yakConfig`.
     *
     * @return array<string, mixed>
     */
    public static function section(Repository $repository, string $section, ?User $user = null): array
    {
        $props = match ($section) {
            'general' => [
                'repository' => fn () => Arr::only(self::repositoryData($repository), [
                    'slug', 'name', 'description', 'agentInstructions', 'gitUrl', 'path', 'defaultBranch',
                    'publicSiteUrl', 'isActive', 'isDefault', 'defaultResponsibleUserId', 'ciSystem',
                    'sentryProject', 'prReviewEnabled', 'githubFullName', 'githubUrl', 'githubNameDiverged',
                ]),
                'options' => fn () => self::options(),
                'sandbox' => fn () => self::sandboxData($repository),
                'docsLinks' => self::docsLinks(),
                'shadowDays' => fn () => self::shadowDays($repository),
                'concernsPullRequest' => Inertia::defer(
                    fn () => ApprovalSettingsPageData::concernsPullRequest($repository),
                    'concerns-pull-request',
                ),
            ],
            'review' => [
                'repository' => fn () => Arr::only(self::repositoryData($repository), [
                    'slug', 'defaultBranch', 'prReviewEnabled', 'reviewPolicy', 'riskProfiles', 'riskProfileActionUrl', 'pathExcludes',
                ]),
                'options' => fn () => self::options(),
                'docsLinks' => self::docsLinks(),
                'coOwnerGate' => Inertia::defer(
                    fn () => app(CoOwnerGateStatus::class)->for($repository),
                    'co-owner-gate',
                ),
            ],
            'previews' => [
                'repository' => fn () => Arr::only(self::repositoryData($repository), [
                    'slug', 'deploymentsEnabled', 'previewRepairEnabled',
                ]),
                'manifest' => fn () => self::manifestData($repository),
                'runningPreviews' => fn () => $repository->branchDeployments()->where('status', 'running')->count(),
                'docsLinks' => self::docsLinks(),
            ],
            'sandbox' => [
                'repository' => fn () => Arr::only(self::repositoryData($repository), ['slug']),
                'sandbox' => fn () => self::sandboxData($repository),
                'setupHistory' => fn () => self::setupHistory($repository),
                'stats' => fn () => self::stats($repository),
                'docsLinks' => self::docsLinks(),
            ],
            'accounts' => [
                'repository' => fn () => Arr::only(self::repositoryData($repository), ['slug']),
                'claudeAccounts' => fn () => RepositoryClaudeAccountData::forRepository($repository, $user),
            ],
            'danger' => [
                'repository' => fn () => Arr::only(self::repositoryData($repository), ['slug', 'name', 'isActive']),
                'canDelete' => fn () => self::canDelete($repository),
                'deleteBlockedReason' => fn () => self::canDelete($repository)
                    ? null
                    : 'This repository has tasks and cannot be deleted. Deactivate it instead.',
            ],
            default => throw new \InvalidArgumentException("Unknown repository settings section [{$section}]."),
        };

        return [
            'header' => self::header($repository),
            ...$props,
            ...self::yakConfigProp($repository),
        ];
    }

    /**
     * @return array{guide: string, config: string, adding: string, setup: string, claudeMd: string, routing: string, prReview: string, refresh: string, rerunSetup: string}
     */
    public static function docsLinks(): array
    {
        return [
            'guide' => Docs::url('repositories'),
            'config' => Docs::url('repositories.config'),
            'adding' => Docs::url('repositories.adding'),
            'setup' => Docs::url('repositories.setup'),
            'claudeMd' => Docs::url('repositories.claude-md'),
            'routing' => Docs::url('repositories.routing'),
            'prReview' => Docs::url('repositories.pr-review'),
            'refresh' => Docs::url('repositories.refresh'),
            'rerunSetup' => Docs::url('repositories.rerun-setup'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function repositoryData(Repository $repository): array
    {
        return [
            'slug' => $repository->slug,
            'name' => $repository->name,
            'description' => $repository->description,
            'agentInstructions' => $repository->agent_instructions,
            'gitUrl' => $repository->git_url,
            'path' => $repository->path,
            'defaultBranch' => $repository->default_branch,
            'publicSiteUrl' => $repository->public_site_url,
            'isActive' => $repository->is_active,
            'isDefault' => $repository->is_default,
            'defaultResponsibleUserId' => $repository->default_responsible_user_id,
            'ciSystem' => $repository->ci_system,
            'sentryProject' => $repository->sentry_project,
            'prReviewEnabled' => (bool) $repository->pr_review_enabled,
            'reviewPolicy' => Arr::only($repository->reviewPolicy(), 'profile_max_age_days'),
            'riskProfiles' => app(RepositoryRiskProfiles::class)->forSettings($repository->slug),
            'riskProfileActionUrl' => route('repos.risk-profile', $repository),
            'deploymentsEnabled' => (bool) $repository->deployments_enabled,
            'previewRepairEnabled' => (bool) $repository->preview_repair_enabled,
            'pathExcludes' => $repository->pr_review_path_excludes,
            'githubFullName' => $repository->github_full_name,
            'githubUrl' => $repository->githubUrl(),
            'githubNameDiverged' => $repository->github_full_name !== $repository->slug,
        ];
    }

    /**
     * @return array{ciSystems: array<int, array{value: string, label: string}>, sentryProjects: array<int, array{value: string, label: string}>, defaultPathExcludes: array<int, string>, users: array<int, array{value: string, label: string}>, defaultReviewPolicy: array<string, mixed>}
     */
    public static function options(): array
    {
        return [
            'ciSystems' => [
                ['value' => 'none', 'label' => 'None'],
                ['value' => 'github_actions', 'label' => 'GitHub Actions'],
                ['value' => 'drone', 'label' => 'Drone'],
            ],
            'sentryProjects' => collect(self::loadSentryProjects())
                ->map(fn (array $project): array => ['value' => $project['slug'], 'label' => $project['name']])
                ->values()
                ->all(),
            'users' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => $user->name])
                ->all(),
            'defaultPathExcludes' => config('yak.pr_review.default_path_excludes'),
            'defaultReviewPolicy' => Arr::only((new Repository)->reviewPolicy(), 'profile_max_age_days'),
        ];
    }

    /**
     * @return array{port: int, healthProbePath: string, coldStart: string, checkoutRefresh: string, wakeTimeoutSeconds: int}
     */
    public static function manifestData(Repository $repository): array
    {
        $manifest = $repository->preview_manifest ?? [];

        return [
            'port' => (int) ($manifest['port'] ?? config('yak.deployments.default_port')),
            'healthProbePath' => (string) ($manifest['health_probe_path'] ?? '/'),
            'coldStart' => (string) ($manifest['cold_start'] ?? ''),
            'checkoutRefresh' => (string) ($manifest['checkout_refresh'] ?? ''),
            'wakeTimeoutSeconds' => (int) ($manifest['wake_timeout_seconds'] ?? 120),
        ];
    }

    /**
     * @return array{snapshot: ?string, baseVersion: ?int, latestBaseVersion: int}
     */
    public static function sandboxData(Repository $repository): array
    {
        return [
            'snapshot' => $repository->sandbox_snapshot,
            'baseVersion' => $repository->sandbox_base_version,
            'latestBaseVersion' => (int) config('yak.sandbox.base_version', 1),
        ];
    }

    /**
     * @return array<int, array{status: string, id: string, url: string, startedAgo: string, duration: string}>
     */
    public static function setupHistory(Repository $repository): array
    {
        return YakTask::query()
            ->where('repo', $repository->slug)
            ->where('mode', TaskMode::Setup)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (YakTask $task): array => [
                'status' => $task->status->value,
                'id' => (string) $task->external_id,
                'url' => route('tasks.show', $task),
                'startedAgo' => $task->created_at?->diffForHumans() ?? '—',
                'duration' => self::formatDuration($task->duration_ms),
            ])
            ->all();
    }

    /**
     * @return array{tasks: int, tasks7d: int, reviews30d: int}
     */
    public static function stats(Repository $repository): array
    {
        return [
            'tasks' => $repository->tasks()->count(),
            'tasks7d' => $repository->tasks()->where('created_at', '>=', now()->subDays(7))->count(),
            'reviews30d' => PrReview::query()
                ->where('repo', $repository->slug)
                ->where('submitted_at', '>=', now()->subDays(30))
                ->count(),
        ];
    }

    /**
     * Whole days since the oldest review recorded in shadow or enforce mode at
     * a level above 1, 0 when there is none. Looks back 90 days.
     */
    public static function shadowDays(Repository $repository): int
    {
        $oldest = PrReview::query()
            ->where('repo', $repository->slug)
            ->where('created_at', '>=', now()->subDays(90))
            ->whereNotNull('risk_assessment')
            ->oldest('created_at')
            ->get(['id', 'risk_assessment', 'created_at'])
            ->first(fn (PrReview $review): bool => in_array($review->risk_assessment['mode'] ?? null, ['shadow', 'enforce'], true)
                && (int) ($review->risk_assessment['level'] ?? 1) >= 2);

        return $oldest?->created_at === null ? 0 : (int) $oldest->created_at->diffInDays(now(), true);
    }

    public static function canDelete(Repository $repository): bool
    {
        return $repository->tasks()->count() === 0;
    }

    private static function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null || $durationMs === 0) {
            return '—';
        }

        $minutes = (int) round($durationMs / 60000);

        if ($minutes < 1) {
            return '1m';
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    private static function loadSentryProjects(): array
    {
        try {
            return Cache::remember('sentry-projects', 300, fn (): array => app(SentryService::class)->listProjects());
        } catch (\Throwable) {
            return [];
        }
    }
}
