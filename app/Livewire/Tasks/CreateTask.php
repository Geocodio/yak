<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskMode;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateTask extends Component
{
    public bool $open = false;

    public string $repo = '';

    public string $taskMode = 'fix';

    public string $description = '';

    /**
     * @return Collection<int, Repository>
     */
    #[Computed]
    public function repos(): Collection
    {
        return Repository::where('is_active', true)->orderBy('slug')->get();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'repo' => ['required', 'string', Rule::exists('repositories', 'slug')->where('is_active', true)],
            'taskMode' => ['required', Rule::in(['fix', 'research'])],
            'description' => ['required', 'string', 'min:3'],
        ]);

        $task = YakTask::create([
            'source' => 'dashboard',
            'repo' => $validated['repo'],
            'external_id' => 'DASH-' . Str::upper(Str::random(8)),
            'description' => trim($validated['description']),
            'mode' => $validated['taskMode'],
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'dashboard', 'repo' => $task->repo]);

        if ($task->mode === TaskMode::Research) {
            ResearchYakJob::dispatch($task);
        } else {
            RunYakJob::dispatch($task);
        }

        $this->reset(['repo', 'taskMode', 'description', 'open']);

        $this->redirect(route('tasks.show', $task), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.tasks.create-task');
    }
}
