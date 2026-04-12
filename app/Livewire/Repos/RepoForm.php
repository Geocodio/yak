<?php

namespace App\Livewire\Repos;

use App\Enums\TaskMode;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use App\Services\SentryService;
use Flux\Flux;
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

    public string $git_url = '';

    public string $default_branch = 'main';

    public bool $is_active = true;

    public bool $is_default = false;

    public string $ci_system = 'github_actions';

    public string $sentry_project = '';

    // GitHub repo picker (create mode only)
    public string $github_search = '';

    public string $selected_github_repo = '';

    /** @var array<int, array{full_name: string, name: string, default_branch: string, clone_url: string, pushed_at: ?string}> */
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
            $this->git_url = $repository->git_url ?? '';
            $this->path = $repository->path;
            $this->default_branch = $repository->default_branch;
            $this->is_active = $repository->is_active;
            $this->is_default = $repository->is_default;
            $this->ci_system = $repository->ci_system;
            $this->sentry_project = $repository->sentry_project ?? '';
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
    public function canDelete(): bool
    {
        if (! $this->repository) {
            return false;
        }

        return $this->repository->tasks()->count() === 0;
    }

    /**
     * @return array<int, array{full_name: string, name: string, default_branch: string, clone_url: string, pushed_at: ?string}>
     */
    #[Computed]
    public function filteredGitHubRepos(): array
    {
        if (empty($this->github_search)) {
            return array_slice($this->github_repos, 0, 10);
        }

        $query = strtolower($this->github_search);

        return array_values(array_slice(array_filter(
            $this->github_repos,
            fn (array $repo): bool => str_contains(strtolower($repo['name']), $query)
                || str_contains(strtolower($repo['full_name']), $query),
        ), 0, 10));
    }

    public function selectGitHubRepo(string $fullName): void
    {
        $repo = collect($this->github_repos)->firstWhere('full_name', $fullName);

        if (! $repo) {
            return;
        }

        $this->selected_github_repo = $repo['full_name'];
        $this->name = $repo['name'];
        $this->git_url = $repo['clone_url'];
        $this->default_branch = $repo['default_branch'];
        $this->github_search = '';
    }

    public function clearSelectedRepo(): void
    {
        $this->selected_github_repo = '';
        $this->name = '';
        $this->git_url = '';
        $this->default_branch = 'main';
        $this->github_search = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $repositoryId = $this->repository?->id;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'git_url' => ['required', 'string', 'max:500', 'url:https'],
            'default_branch' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'ci_system' => ['required', 'string', Rule::in(['github_actions', 'drone'])],
            'sentry_project' => ['nullable', 'string', 'max:255'],
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
            $this->slug = $this->generateUniqueSlug($this->name);
            $this->path = '/home/yak/repos/' . $this->slug;
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
            'git_url' => $this->git_url,
            'path' => $this->path,
            'default_branch' => $this->default_branch,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'ci_system' => $this->ci_system,
            'sentry_project' => $this->sentry_project ?: null,
        ];

        if ($this->repository) {
            $this->repository->update($data);
            Flux::toast('Repository updated.');
        } else {
            $this->repository = Repository::create($data);
            $this->dispatchSetupTask();
            Flux::toast('Repository created. Setup task dispatched.');
        }

        $this->redirectRoute('repos.edit', $this->repository, navigate: true);
    }

    public function rerunSetup(): void
    {
        if ($this->repository) {
            $this->dispatchSetupTask();
            Flux::toast('Setup task dispatched.');
        }
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

    protected function dispatchSetupTask(): void
    {
        if (! $this->repository) {
            return;
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
