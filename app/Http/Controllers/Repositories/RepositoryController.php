<?php

namespace App\Http\Controllers\Repositories;

use App\Actions\ApplyPrReviewToOpenPulls;
use App\Actions\DispatchRepositorySetupTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\SaveRepositoryRequest;
use App\Http\Resources\RepositorySettingsData;
use App\Http\Resources\RepositorySummaryData;
use App\Models\PrReview;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        return Inertia::render('Repositories/Create', [
            'options' => fn () => RepositorySettingsData::options(),
            'docsLinks' => RepositorySettingsData::docsLinks(),
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

        $dispatchSetup($repository, $request->user());

        if (($validated['pr_review_enabled'] ?? false) && ($validated['apply_to_open_prs'] ?? false)) {
            $applyPrReview($repository);
        }

        return redirect()->route('repos.edit', $repository)->with('success', 'Repository created. Setup task dispatched.');
    }

    public function edit(Request $request, Repository $repository): Response
    {
        return Inertia::render('Repositories/Settings/General', RepositorySettingsData::section($repository, 'general', $request->user()));
    }

    public function update(SaveRepositoryRequest $request, Repository $repository, ApplyPrReviewToOpenPulls $applyPrReview): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['is_default'] ?? false) {
            Repository::query()->where('is_default', true)->where('id', '!=', $repository->id)->update(['is_default' => false]);
        }

        $wasEnabled = (bool) $repository->pr_review_enabled;

        $data = $this->attributesFromRequest($validated);

        if (! empty($validated['manifest'])) {
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

        return back()->with('success', 'Saved.');
    }

    public function destroy(Repository $repository): RedirectResponse
    {
        if (! RepositorySettingsData::canDelete($repository)) {
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
            'default_responsible_user_id' => $validated['default_responsible_user_id'] ?? null,
            'ci_system' => $validated['ci_system'],
            'sentry_project' => ($validated['sentry_project'] ?? '') !== '' ? $validated['sentry_project'] : null,
            'pr_review_enabled' => $validated['pr_review_enabled'] ?? false,
            ...(isset($validated['pr_review_policy']) ? ['pr_review_policy' => $validated['pr_review_policy']] : []),
            'pr_review_path_excludes' => $validated['path_excludes'] ?? null,
            'deployments_enabled' => $validated['deployments_enabled'] ?? false,
            ...(array_key_exists('preview_repair_enabled', $validated) ? ['preview_repair_enabled' => (bool) $validated['preview_repair_enabled']] : []),
        ];
    }

    /**
     * Only the keys present in the request become attributes, so a section
     * save leaves every other column untouched.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFromRequest(array $validated): array
    {
        $attributes = Arr::only($validated, [
            'slug', 'name', 'git_url', 'path', 'default_branch', 'ci_system', 'is_active', 'is_default',
            'default_responsible_user_id', 'pr_review_enabled', 'pr_review_policy', 'deployments_enabled',
            'preview_repair_enabled',
        ]);

        foreach (['description', 'public_site_url', 'sentry_project'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = ($validated[$key] ?? '') !== '' ? $validated[$key] : null;
            }
        }

        if (array_key_exists('agent_instructions', $validated)) {
            $attributes['agent_instructions'] = trim((string) $validated['agent_instructions']) !== '' ? $validated['agent_instructions'] : null;
        }

        if (array_key_exists('path_excludes', $validated)) {
            $attributes['pr_review_path_excludes'] = $validated['path_excludes'];
        }

        return $attributes;
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
