<?php

namespace App\Livewire\Repos;

use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\YakTask;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Repository')]
class RepoForm extends Component
{
    public ?Repository $repository = null;

    public string $slug = '';

    public string $name = '';

    public string $path = '';

    public string $default_branch = 'main';

    public bool $is_active = true;

    public bool $is_default = false;

    public string $ci_system = 'github_actions';

    public string $sentry_project = '';

    public string $notes = '';

    public function mount(?Repository $repository = null): void
    {
        if ($repository?->exists) {
            $this->repository = $repository;
            $this->slug = $repository->slug;
            $this->name = $repository->name;
            $this->path = $repository->path;
            $this->default_branch = $repository->default_branch;
            $this->is_active = $repository->is_active;
            $this->is_default = $repository->is_default;
            $this->ci_system = $repository->ci_system;
            $this->sentry_project = $repository->sentry_project ?? '';
            $this->notes = $repository->notes ?? '';
        }
    }

    public function updatedName(): void
    {
        if (! $this->repository) {
            $this->slug = str($this->name)->slug()->toString();
        }
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
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $repositoryId = $this->repository?->id;

        return [
            'slug' => ['required', 'string', 'max:255', Rule::unique('repositories', 'slug')->ignore($repositoryId)],
            'name' => ['required', 'string', 'max:255'],
            'path' => ['required', 'string', 'max:500', 'starts_with:/'],
            'default_branch' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'ci_system' => ['required', 'string', Rule::in(['github_actions', 'drone'])],
            'sentry_project' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(): void
    {
        $this->validate();

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
            'path' => $this->path,
            'default_branch' => $this->default_branch,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'ci_system' => $this->ci_system,
            'sentry_project' => $this->sentry_project ?: null,
            'notes' => $this->notes ?: null,
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
            'external_id' => 'setup-'.Str::random(8),
            'mode' => TaskMode::Setup,
            'description' => "Setup repository: {$this->repository->name}",
            'source' => 'dashboard',
        ]);

        $this->repository->update([
            'setup_task_id' => $task->id,
            'setup_status' => 'pending',
        ]);
    }
}
