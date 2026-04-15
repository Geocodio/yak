<div wire:poll.15s>
    {{-- Tabs --}}
    <div class="mb-5 flex border-b border-yak-tan/40" data-testid="task-tabs">
        <button
            type="button"
            wire:click="$set('tab', 'tasks')"
            class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors {{ $tab === 'tasks' ? 'border-yak-orange text-yak-orange' : 'border-transparent text-yak-blue hover:text-yak-slate' }}"
            data-testid="tab-tasks"
        >
            <span>Tasks</span>
            <span class="rounded-full px-2 py-0.5 text-xs {{ $tab === 'tasks' ? 'bg-yak-orange/15 text-yak-orange' : 'bg-yak-cream-dark text-yak-blue' }}">{{ $this->tasksCount }}</span>
        </button>
        <button
            type="button"
            wire:click="$set('tab', 'setup')"
            class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors {{ $tab === 'setup' ? 'border-yak-orange text-yak-orange' : 'border-transparent text-yak-blue hover:text-yak-slate' }}"
            data-testid="tab-setup"
        >
            <span>Setup</span>
            <span class="rounded-full px-2 py-0.5 text-xs {{ $tab === 'setup' ? 'bg-yak-orange/15 text-yak-orange' : 'bg-yak-cream-dark text-yak-blue' }}">{{ $this->setupCount }}</span>
        </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2" data-testid="task-filters">
        <div class="w-full sm:w-44">
            <flux:select wire:model.live="status" size="sm" aria-label="Filter by status">
                <flux:select.option value="">All statuses</flux:select.option>
                @foreach(\App\Enums\TaskStatus::cases() as $statusOption)
                    <flux:select.option value="{{ $statusOption->value }}">{{ str_replace('_', ' ', ucfirst($statusOption->value)) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if($tab === 'tasks')
            <div class="w-full sm:w-44">
                <flux:select wire:model.live="source" size="sm" aria-label="Filter by source">
                    <flux:select.option value="">All sources</flux:select.option>
                    @foreach($this->sources as $sourceOption)
                        <flux:select.option value="{{ $sourceOption }}">{{ ucfirst($sourceOption) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="w-full sm:w-56">
            <flux:select wire:model.live="repo" size="sm" aria-label="Filter by repo">
                <flux:select.option value="">All repos</flux:select.option>
                @foreach($this->repos as $repoOption)
                    <flux:select.option value="{{ $repoOption }}">{{ $repoOption }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if($status !== '' || $source !== '' || $repo !== '')
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters" data-testid="clear-filters">Clear</flux:button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Status</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Source</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Repo</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">ID</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Description</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Duration</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">PR</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($this->tasks as $task)
                    <tr wire:key="task-{{ $task->id }}" class="relative h-14 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3 py-2 sm:px-5">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="absolute inset-0" aria-label="Open task {{ $task->external_id ?? $task->id }}"></a>
                            <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Tasks\TaskList::statusBadgeClasses($task->status) }}">
                                {{ str_replace('_', ' ', $task->status->value) }}
                            </span>
                        </td>
                        <td class="px-3 py-2 sm:px-5">
                            <div class="flex items-center gap-1.5">
                                @if($task->source === 'slack')
                                    <flux:icon.chat-bubble-left class="!size-4 text-zinc-400" />
                                @elseif($task->source === 'sentry')
                                    <flux:icon.shield-exclamation class="!size-4 text-zinc-400" />
                                @elseif($task->source === 'linear')
                                    <flux:icon.bolt class="!size-4 text-zinc-400" />
                                @else
                                    <flux:icon.command-line class="!size-4 text-zinc-400" />
                                @endif
                                <span class="text-zinc-700 dark:text-zinc-300">{{ ucfirst($task->source ?? 'manual') }}</span>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">
                            @if($task->repository)
                                <a href="{{ route('repos.edit', $task->repository) }}" wire:navigate class="relative font-medium text-accent hover:underline">{{ $task->repo }}</a>
                            @else
                                {{ $task->repo ?? '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-2 sm:px-5">
                            @if($task->external_url)
                                <a href="{{ $task->external_url }}" target="_blank" class="relative font-medium text-accent hover:underline">{{ $task->external_id }}</a>
                            @else
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $task->external_id ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="max-w-xs truncate px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">{{ \Illuminate\Support\Str::limit($task->description, 60) }}</td>
                        <td class="px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">{{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }}</td>
                        <td class="px-3 py-2 sm:px-5">
                            @if($task->pr_url)
                                <a href="{{ $task->pr_url }}" target="_blank" class="relative font-medium text-accent hover:underline">PR</a>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-12 text-center text-zinc-500 sm:px-5 dark:text-zinc-400">
                            No tasks found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($this->tasks->hasPages())
        <div class="mt-4">
            {{ $this->tasks->links() }}
        </div>
    @endif
</div>
