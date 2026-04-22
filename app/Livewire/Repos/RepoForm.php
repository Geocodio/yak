<?php

namespace App\Livewire\Repos;

use App\Actions\ApplyPrReviewToOpenPulls;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Sentry\Service as SentryService;
use App\Enums\TaskMode;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Repository')]
class RepoForm extends Component
{
    public ?Repository $repository = null;

    public string $name = '';

    public string $description = '';

    public string $agent_instructions = '';

    public string $git_url = '';

    public string $default_branch = 'main';

    public bool $is_active = true;

    public bool $is_default = false;

    public string $ci_system = 'github_actions';

    public ?string $detected_ci_system = null;

    public string $sentry_project = '';

    public bool $pr_review_enabled = false;

    public bool $apply_to_open_prs = true;

    /** @var array<int, string> */
    public array $path_excludes = [];

    public string $path_exclude_input = '';

    public bool $using_defaults = true;

    // GitHub repo picker (create mode only)
    public string $github_search = '';

    public string $selected_github_repo = '';

    /** @var array<int, array{full_name: string, name: string, description: ?string, default_branch: string, clone_url: string, pushed_at: ?string, private: bool, language: ?string}> */
    public array $github_repos = [];

    /** @var array<int, array{slug: string, name: string}> */
    public array $sentry_projects = [];

    // Keep for edit mode compatibility
    public string $slug = '';

    public string $path = '';

    public function mount(?Repository $repository = null): void
    {
        if ($repository?->exists) {
            $this->repository = $repository;
            $this->slug = $repository->slug;
            $this->name = $repository->name;
            $this->description = $repository->description ?? '';
            $this->agent_instructions = $repository->agent_instructions ?? '';
            $this->git_url = $repository->git_url ?? '';
            $this->path = $repository->path;
            $this->default_branch = $repository->default_branch;
            $this->is_active = $repository->is_active;
            $this->is_default = $repository->is_default;
            $this->ci_system = $repository->ci_system;
            $this->sentry_project = $repository->sentry_project ?? '';
            $this->pr_review_enabled = (bool) $repository->pr_review_enabled;

            if ($repository->pr_review_path_excludes !== null) {
                $this->path_excludes = array_values($repository->pr_review_path_excludes);
                $this->using_defaults = false;
            } else {
                $this->path_excludes = [];
                $this->using_defaults = true;
            }
        } else {
            $this->loadGitHubRepos();
        }

        $this->loadSentryProjects();
    }

    #[Computed]
    public function isEditing(): bool
    {
        return $this->repository !== null;
    }

    #[Computed]
    public function sentryProjectHint(): string
    {
        $configured = (bool) config('yak.channels.sentry.auth_token')
            && (bool) config('yak.channels.sentry.org_slug');

        if ($configured && empty($this->sentry_projects)) {
            return 'Could not load Sentry projects — make sure the auth token has the org:read and project:read scopes. Enter the slug manually in the meantime.';
        }

        return 'Maps incoming Sentry webhooks to this repository.';
    }

    /**
     * @return Collection<int, YakTask>
     */
    #[Computed]
    public function setupTasks(): Collection
    {
        if (! $this->repository) {
            /** @var Collection<int, YakTask> $empty */
            $empty = new Collection;

            return $empty;
        }

        return YakTask::query()
            ->where('repo', $this->repository->slug)
            ->where('mode', TaskMode::Setup)
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function canDelete(): bool
    {
        if (! $this->repository) {
            return false;
        }

        return $this->repository->tasks()->count() === 0;
    }

    /**
     * @return array<int, array{full_name: string, name: string, default_branch: string, clone_url: string, pushed_at: ?string, private: bool, language: ?string}>
     */
    #[Computed]
    public function filteredGitHubRepos(): array
    {
        $alreadyAdded = array_flip(Repository::pluck('slug')->all());

        $available = array_filter(
            $this->github_repos,
            fn (array $repo): bool => ! isset($alreadyAdded[$repo['full_name']]),
        );

        if (empty($this->github_search)) {
            return array_slice($available, 0, 50);
        }

        $query = strtolower($this->github_search);

        return array_slice(array_filter(
            $available,
            fn (array $repo): bool => str_contains(strtolower($repo['name']), $query)
                || str_contains(strtolower($repo['full_name']), $query),
        ), 0, 50);
    }

    public function selectGitHubRepo(string $fullName): void
    {
        $repo = collect($this->github_repos)->firstWhere('full_name', $fullName);

        if (! $repo) {
            return;
        }

        $this->selected_github_repo = $repo['full_name'];
        $this->name = $repo['name'];
        $this->description = $repo['description'] ?? '';
        $this->git_url = $repo['clone_url'];
        $this->default_branch = $repo['default_branch'];
        $this->github_search = '';

        $this->detected_ci_system = $this->detectCiSystem($repo['full_name']);
        if ($this->detected_ci_system !== null) {
            $this->ci_system = $this->detected_ci_system;
        }

        if ($this->sentry_project === '') {
            $this->sentry_project = $this->guessSentryProject($repo['name']) ?? '';
        }
    }

    public function clearSelectedRepo(): void
    {
        $this->selected_github_repo = '';
        $this->name = '';
        $this->git_url = '';
        $this->default_branch = 'main';
        $this->github_search = '';
        $this->detected_ci_system = null;
    }

    /**
     * Return the Sentry project slug that best matches the given repo name,
     * or null if nothing looks like a good fit.
     *
     * Uses similar_text percentage on both slug and name so close variants
     * like "geocodio-website" ↔ "geocodio_website" still match, but we
     * require 60%+ similarity to avoid preselecting random projects.
     */
    protected function guessSentryProject(string $repoName): ?string
    {
        if (empty($this->sentry_projects)) {
            return null;
        }

        $needle = strtolower($repoName);
        $bestSlug = null;
        $bestScore = 0.0;

        foreach ($this->sentry_projects as $project) {
            $candidates = [
                strtolower($project['slug']),
                strtolower($project['name']),
            ];

            foreach ($candidates as $candidate) {
                similar_text($needle, $candidate, $percent);
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $bestSlug = $project['slug'];
                }
            }
        }

        return $bestScore >= 60.0 ? $bestSlug : null;
    }

    protected function detectCiSystem(string $repoSlug): ?string
    {
        try {
            $installationId = (int) config('yak.channels.github.installation_id');

            if (! $installationId) {
                return null;
            }

            return Cache::remember(
                "github-ci-detect:{$repoSlug}",
                300,
                fn (): string => app(GitHubAppService::class)->detectCiSystem($installationId, $repoSlug),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $repositoryId = $this->repository?->id;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'agent_instructions' => ['nullable', 'string', 'max:10000'],
            'git_url' => ['required', 'string', 'max:500', 'url:https'],
            'default_branch' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'ci_system' => ['required', 'string', Rule::in(['github_actions', 'drone', 'none'])],
            'sentry_project' => ['nullable', 'string', 'max:255'],
            'pr_review_enabled' => ['boolean'],
            'apply_to_open_prs' => ['boolean'],
        ];

        if ($this->repository) {
            $rules['slug'] = ['required', 'string', 'max:255', Rule::unique('repositories', 'slug')->ignore($repositoryId)];
            $rules['path'] = ['required', 'string', 'max:500', 'starts_with:/'];
        }

        return $rules;
    }

    public function save(): void
    {
        $this->validate();

        // Auto-generate slug and path for new repositories
        if (! $this->repository) {
            if ($this->selected_github_repo !== '') {
                $this->slug = $this->selected_github_repo;
                $this->path = '/home/yak/repos/' . str($this->name)->slug();
            } else {
                $this->slug = $this->generateUniqueSlug($this->name);
                $this->path = '/home/yak/repos/' . $this->slug;
            }
        }

        if ($this->is_default) {
            $query = Repository::query()->where('is_default', true);
            if ($this->repository) {
                $query->where('id', '!=', $this->repository->id);
            }
            $query->update(['is_default' => false]);
        }

        $data = [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'agent_instructions' => trim($this->agent_instructions) !== '' ? $this->agent_instructions : null,
            'git_url' => $this->git_url,
            'path' => $this->path,
            'default_branch' => $this->default_branch,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'ci_system' => $this->ci_system,
            'sentry_project' => $this->sentry_project ?: null,
            'pr_review_enabled' => $this->pr_review_enabled,
            'pr_review_path_excludes' => $this->using_defaults ? null : $this->path_excludes,
        ];

        $wasEnabled = (bool) ($this->repository->pr_review_enabled ?? false);

        if ($this->repository) {
            $this->repository->update($data);
            Flux::toast('Repository updated.');
        } else {
            $this->repository = Repository::create($data);
            $this->dispatchSetupTask();
            Flux::toast('Repository created. Setup task dispatched.');
        }

        if ($this->pr_review_enabled && ! $wasEnabled && $this->apply_to_open_prs) {
            app(ApplyPrReviewToOpenPulls::class)($this->repository);
        }

        $this->redirectRoute('repos.edit', $this->repository, navigate: true);
    }

    public function addPathExclude(): void
    {
        $pattern = trim($this->path_exclude_input);

        if ($pattern === '' || in_array($pattern, $this->path_excludes, true)) {
            return;
        }

        if (! preg_match('#^[A-Za-z0-9_./*?\-]+$#', $pattern)) {
            $this->addError('path_exclude_input', 'Invalid glob pattern.');

            return;
        }

        $this->path_excludes[] = $pattern;
        $this->path_exclude_input = '';
        $this->using_defaults = false;
    }

    public function removePathExclude(int $index): void
    {
        unset($this->path_excludes[$index]);
        $this->path_excludes = array_values($this->path_excludes);
        $this->using_defaults = false;
    }

    public function resetPathExcludes(): void
    {
        $this->path_excludes = [];
        $this->using_defaults = true;
    }

    public function reviewAllOpenPrs(): void
    {
        if (! $this->repository) {
            return;
        }

        $count = app(ApplyPrReviewToOpenPulls::class)($this->repository);

        Flux::toast("Enqueued review for {$count} open PRs.");
    }

    public function rerunSetup(): void
    {
        if (! $this->repository) {
            return;
        }

        $task = $this->dispatchSetupTask();

        $this->redirectRoute('tasks.show', $task, navigate: true);
    }

    public function toggleActive(): void
    {
        if ($this->repository) {
            $this->is_active = ! $this->is_active;
            $this->repository->update(['is_active' => $this->is_active]);
            Flux::toast($this->is_active ? 'Repository activated.' : 'Repository deactivated.');
        }
    }

    public function delete(): void
    {
        if (! $this->repository || ! $this->canDelete()) {
            Flux::toast('Cannot delete a repository with tasks.', variant: 'danger');

            return;
        }

        $this->repository->delete();
        Flux::toast('Repository deleted.');
        $this->redirectRoute('repos', navigate: true);
    }

    protected function dispatchSetupTask(): ?YakTask
    {
        if (! $this->repository) {
            return null;
        }

        $task = YakTask::create([
            'repo' => $this->repository->slug,
            'external_id' => 'setup-' . Str::random(8),
            'mode' => TaskMode::Setup,
            'description' => "Setup repository: {$this->repository->name}",
            'source' => 'dashboard',
        ]);

        $this->repository->update([
            'setup_task_id' => $task->id,
            'setup_status' => 'pending',
        ]);

        SetupYakJob::dispatch($task);

        return $task;
    }

    protected function loadGitHubRepos(): void
    {
        try {
            $installationId = (int) config('yak.channels.github.installation_id');

            if (! $installationId) {
                return;
            }

            $this->github_repos = Cache::remember('github-installation-repos', 300, function () use ($installationId): array {
                return app(GitHubAppService::class)->listInstallationRepositories($installationId);
            });
        } catch (\Throwable) {
            $this->github_repos = [];
        }
    }

    protected function loadSentryProjects(): void
    {
        try {
            $this->sentry_projects = Cache::remember('sentry-projects', 300, function (): array {
                return app(SentryService::class)->listProjects();
            });
        } catch (\Throwable) {
            $this->sentry_projects = [];
        }
    }

    protected function generateUniqueSlug(string $name): string
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
