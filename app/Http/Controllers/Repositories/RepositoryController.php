<?php

namespace App\Http\Controllers\Repositories;

use App\Actions\ApplyPrReviewToOpenPulls;
use App\Actions\DispatchRepositorySetupTask;
use App\Channels\Sentry\Service as SentryService;
use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\SaveRepositoryRequest;
use App\Http\Resources\RepositorySummaryData;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Support\Docs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Repositories/Index', [
            'repositories' => fn () => $this->repositoryRows(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Repositories/Form', [
            'repository' => null,
            'options' => fn () => $this->options(),
            'manifest' => null,
            'sandbox' => null,
            'setupHistory' => [],
            'stats' => null,
            'canDelete' => false,
            'deleteBlockedReason' => null,
            'docsUrl' => Docs::url('repositories'),
        ]);
    }

    public function store(SaveRepositoryRequest $request, ApplyPrReviewToOpenPulls $applyPrReview, DispatchRepositorySetupTask $dispatchSetup): RedirectResponse
    {
        $validated = $request->validated();

        $selectedGithubRepo = (string) ($validated['selected_github_repo'] ?? '');

        $slug = $selectedGithubRepo !== '' ? $selectedGithubRepo : $this->generateUniqueSlug($validated['name']);
        $path = '/home/yak/repos/' . ($selectedGithubRepo !== '' ? str($validated['name'])->slug() : $slug);

        if ($validated['is_default'] ?? false) {
            Repository::query()->where('is_default', true)->update(['is_default' => false]);
        }

        $data = $this->repositoryDataFromRequest($validated, $slug, $path);

        if ($selectedGithubRepo !== '') {
            $data['github_full_name'] = $selectedGithubRepo;
            $data['github_repo_id'] = $validated['selected_github_repo_id'] ?? null;
        }

        $repository = Repository::create($data);

        $dispatchSetup($repository);

        if (($validated['pr_review_enabled'] ?? false) && ($validated['apply_to_open_prs'] ?? false)) {
            $applyPrReview($repository);
        }

        return redirect()->route('repos.edit', $repository)->with('success', 'Repository created. Setup task dispatched.');
    }

    public function edit(Repository $repository): Response
    {
        return Inertia::render('Repositories/Form', [
            'repository' => fn () => $this->repositoryData($repository),
            'options' => fn () => $this->options(),
            'manifest' => fn () => $this->manifestData($repository),
            'sandbox' => fn () => $this->sandboxData($repository),
            'setupHistory' => fn () => $this->setupHistory($repository),
            'stats' => fn () => $this->stats($repository),
            'canDelete' => fn () => $this->canDelete($repository),
            'deleteBlockedReason' => fn () => $this->canDelete($repository)
                ? null
                : 'This repository has tasks and cannot be deleted. Deactivate it instead.',
            'docsUrl' => Docs::url('repositories'),
        ]);
    }

    public function update(SaveRepositoryRequest $request, Repository $repository, ApplyPrReviewToOpenPulls $applyPrReview): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['is_default'] ?? false) {
            Repository::query()->where('is_default', true)->where('id', '!=', $repository->id)->update(['is_default' => false]);
        }

        $wasEnabled = (bool) $repository->pr_review_enabled;

        $data = $this->repositoryDataFromRequest($validated, $validated['slug'], $validated['path']);

        if (array_key_exists('manifest', $validated)) {
            $data['preview_manifest'] = [
                'port' => $validated['manifest']['port'],
                'health_probe_path' => $validated['manifest']['health_probe_path'],
                'cold_start' => $validated['manifest']['cold_start'] ?? '',
                'checkout_refresh' => $validated['manifest']['checkout_refresh'] ?? '',
                'wake_timeout_seconds' => $validated['manifest']['wake_timeout_seconds'] ?? 120,
            ];
        }

        $repository->update($data);

        if (($validated['pr_review_enabled'] ?? false) && ! $wasEnabled && ($validated['apply_to_open_prs'] ?? false)) {
            $applyPrReview($repository);
        }

        return redirect()->route('repos.edit', $repository)->with('success', 'Repository updated.');
    }

    public function destroy(Repository $repository): RedirectResponse
    {
        if (! $this->canDelete($repository)) {
            return redirect()->route('repos.edit', $repository)->with('error', 'Cannot delete a repository with tasks.');
        }

        $repository->delete();

        return redirect()->route('repos')->with('success', 'Repository deleted.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function repositoryRows(): array
    {
        $cutoff = now()->subDays(30);

        $reviewCounts = PrReview::query()
            ->where('submitted_at', '>=', $cutoff)
            ->selectRaw('repo, COUNT(*) as count')
            ->groupBy('repo')
            ->pluck('count', 'repo');

        $repos = Repository::query()
            ->withCount([
                'tasks',
                'tasks as tasks_recent_count' => fn ($query) => $query->where('created_at', '>=', now()->subDays(7)),
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return $repos
            ->map(fn (Repository $repo): array => RepositorySummaryData::from($repo, (int) ($reviewCounts[$repo->slug] ?? 0)))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function repositoryDataFromRequest(array $validated, string $slug, string $path): array
    {
        return [
            'slug' => $slug,
            'name' => $validated['name'],
            'description' => ($validated['description'] ?? '') !== '' ? $validated['description'] : null,
            'agent_instructions' => trim((string) ($validated['agent_instructions'] ?? '')) !== '' ? $validated['agent_instructions'] : null,
            'git_url' => $validated['git_url'],
            'path' => $path,
            'default_branch' => $validated['default_branch'],
            'public_site_url' => ($validated['public_site_url'] ?? '') !== '' ? $validated['public_site_url'] : null,
            'is_active' => $validated['is_active'] ?? true,
            'is_default' => $validated['is_default'] ?? false,
            'ci_system' => $validated['ci_system'],
            'sentry_project' => ($validated['sentry_project'] ?? '') !== '' ? $validated['sentry_project'] : null,
            'pr_review_enabled' => $validated['pr_review_enabled'] ?? false,
            'pr_review_path_excludes' => $validated['path_excludes'] ?? null,
            'deployments_enabled' => $validated['deployments_enabled'] ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repositoryData(Repository $repository): array
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
            'ciSystem' => $repository->ci_system,
            'sentryProject' => $repository->sentry_project,
            'prReviewEnabled' => (bool) $repository->pr_review_enabled,
            'deploymentsEnabled' => (bool) $repository->deployments_enabled,
            'pathExcludes' => $repository->pr_review_path_excludes,
            'githubFullName' => $repository->github_full_name,
            'githubUrl' => $repository->githubUrl(),
            'githubNameDiverged' => $repository->github_full_name !== $repository->slug,
        ];
    }

    /**
     * @return array{ciSystems: array<int, array{value: string, label: string}>, sentryProjects: array<int, array{value: string, label: string}>, defaultPathExcludes: array<int, string>}
     */
    private function options(): array
    {
        return [
            'ciSystems' => [
                ['value' => 'none', 'label' => 'None'],
                ['value' => 'github_actions', 'label' => 'GitHub Actions'],
                ['value' => 'drone', 'label' => 'Drone'],
            ],
            'sentryProjects' => collect($this->loadSentryProjects())
                ->map(fn (array $project): array => ['value' => $project['slug'], 'label' => $project['name']])
                ->values()
                ->all(),
            'defaultPathExcludes' => config('yak.pr_review.default_path_excludes'),
        ];
    }

    /**
     * @return array{port: int, healthProbePath: string, coldStart: string, checkoutRefresh: string, wakeTimeoutSeconds: int}
     */
    private function manifestData(Repository $repository): array
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
    private function sandboxData(Repository $repository): array
    {
        return [
            'snapshot' => $repository->sandbox_snapshot,
            'baseVersion' => $repository->sandbox_base_version,
            'latestBaseVersion' => (int) config('yak.sandbox.base_version', 1),
        ];
    }

    /**
     * @return array<int, array{status: string, id: string, startedAgo: string, duration: string}>
     */
    private function setupHistory(Repository $repository): array
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
                'startedAgo' => $task->created_at?->diffForHumans() ?? '—',
                'duration' => $this->formatDuration($task->duration_ms),
            ])
            ->all();
    }

    /**
     * @return array{tasks: int, tasks7d: int, reviews30d: int}
     */
    private function stats(Repository $repository): array
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

    private function canDelete(Repository $repository): bool
    {
        return $repository->tasks()->count() === 0;
    }

    private function formatDuration(?int $durationMs): string
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
    private function loadSentryProjects(): array
    {
        try {
            return Cache::remember('sentry-projects', 300, fn (): array => app(SentryService::class)->listProjects());
        } catch (\Throwable) {
            return [];
        }
    }

    private function generateUniqueSlug(string $name): string
    {
        $baseSlug = str($name)->slug()->toString();
        $slug = $baseSlug;
        $counter = 1;

        while (Repository::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
