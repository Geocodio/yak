<div wire:poll.15s>
    <div x-data="{ newTaskOpen: false }">
        {{-- Getting started card (shown when the install is bare and the user hasn't dismissed it) --}}
    @if($this->showSetupCard)
        <div class="mb-5 rounded-[20px] border border-yak-orange/30 bg-gradient-to-br from-yak-orange/5 to-yak-cream p-5" data-testid="setup-card">
            <div class="flex items-start gap-4">
                <div class="shrink-0 rounded-full bg-yak-orange/15 p-2">
                    <flux:icon.rocket-launch class="!size-5 text-yak-orange" />
                </div>
                <div class="flex-1">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold uppercase tracking-wider text-yak-orange">Getting started with Yak</h2>
                            <p class="mt-1 text-sm text-yak-blue">Three small steps and you're ready to ship papercuts.</p>
                        </div>
                        <button
                            type="button"
                            wire:click="dismissSetupCard"
                            class="shrink-0 text-yak-tan hover:text-yak-slate transition-colors"
                            aria-label="Dismiss"
                            data-testid="dismiss-setup-card"
                        >
                            <flux:icon.x-mark class="!size-4" />
                        </button>
                    </div>
                    <ul class="mt-4 space-y-3">
                        @foreach($this->setupChecklist as $item)
                            <li class="flex items-start gap-3" data-testid="setup-step">
                                @if($item['done'])
                                    <div class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-yak-green/20 text-yak-green">
                                        <flux:icon.check class="!size-3.5" />
                                    </div>
                                @else
                                    <div class="mt-0.5 size-5 shrink-0 rounded-full border border-yak-tan"></div>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <a
                                        href="{{ $item['url'] }}"
                                        @if($item['external']) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                                        class="text-sm font-medium text-yak-slate hover:text-yak-orange transition-colors {{ $item['done'] ? 'line-through decoration-yak-tan/60' : '' }}"
                                    >
                                        {{ $item['label'] }}
                                        @if($item['external'])
                                            <flux:icon.arrow-top-right-on-square class="!size-3 inline-block opacity-60" />
                                        @endif
                                    </a>
                                    <p class="mt-0.5 text-xs text-yak-blue">{{ $item['description'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

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
            wire:click="$set('tab', 'reviews')"
            class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors {{ $tab === 'reviews' ? 'border-yak-orange text-yak-orange' : 'border-transparent text-yak-blue hover:text-yak-slate' }}"
            data-testid="tab-reviews"
        >
            <span>PR Reviews</span>
            <span class="rounded-full px-2 py-0.5 text-xs {{ $tab === 'reviews' ? 'bg-yak-orange/15 text-yak-orange' : 'bg-yak-cream-dark text-yak-blue' }}">{{ $this->reviewsCount }}</span>
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

        <div class="ml-auto">
            <flux:button
                size="sm"
                variant="primary"
                icon="plus"
                @click="newTaskOpen = true"
                data-testid="new-task-trigger"
            >New task</flux:button>
        </div>
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
                    {{-- `transform: translateZ(0)` forces Safari to treat the <tr> as a containing block for
                         the stretched-link anchor below. Without it, Safari leaks the anchor up to the viewport,
                         every row's anchor stacks, and every tap hits the last row. --}}
                    <tr wire:key="task-{{ $task->id }}" class="relative h-14 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50" style="transform: translateZ(0)">
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
                        <td class="max-w-xs truncate px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">
                            @php($children = $task->branch_name ? ($this->descendantsByBranch[$task->branch_name] ?? collect()) : collect())
                            {{ \Illuminate\Support\Str::limit($task->description, 60) }}
                            @if($children->isNotEmpty())
                                <span class="ml-2 rounded-full bg-[rgba(212,145,94,0.12)] px-2 py-0.5 text-[10px] font-semibold text-[#d4915e]">{{ $children->count() }} follow-ups</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">{{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }}</td>
                        <td class="px-3 py-2 sm:px-5">
                            @if($task->pr_url)
                                <a href="{{ $task->pr_url }}" target="_blank" class="relative font-medium text-accent hover:underline">PR</a>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                    </tr>
                    @foreach($children as $child)
                        <tr wire:key="child-{{ $child->id }}" class="relative h-12 bg-zinc-50/60 transition-colors hover:bg-zinc-100/60 dark:bg-zinc-800/30 dark:hover:bg-zinc-800/60" style="transform: translateZ(0)">
                            <td class="py-2 pl-8 pr-3 sm:pr-5">
                                <a href="{{ route('tasks.show', $child) }}" wire:navigate class="absolute inset-0" aria-label="Open task {{ $child->external_id ?? $child->id }}"></a>
                                <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Tasks\TaskList::statusBadgeClasses($child->status) }}">
                                    {{ str_replace('_', ' ', $child->status->value) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">—</td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">—</td>
                            <td class="px-3 py-2 sm:px-5">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $child->external_id ?? '—' }}</span>
                            </td>
                            <td class="max-w-xs truncate px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($child->description, 60) }}</td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">{{ \App\Livewire\Tasks\TaskList::formatDuration($child->duration_ms) }}</td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">—</td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-16 text-center text-zinc-500 sm:px-5 dark:text-zinc-400">
                            <div class="flex flex-col items-center gap-3">
                                <p class="text-sm">No tasks yet. Yak picks up work from your configured channels.</p>
                                <x-doc-link anchor="channels" class="text-sm">How tasks get created</x-doc-link>
                            </div>
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

    {{-- New task slideover --}}
    <div x-show="newTaskOpen" x-cloak class="fixed inset-0 z-50" style="display:none">
        {{-- Backdrop --}}
        <div
            class="fixed inset-0 bg-black/30"
            @click="newTaskOpen = false"
            x-transition.opacity
        ></div>
        {{-- Panel --}}
        <div
            class="fixed inset-y-0 right-0 flex w-full max-w-md flex-col overflow-y-auto bg-white p-6 shadow-2xl dark:bg-zinc-900"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @keydown.escape.window="newTaskOpen = false"
        >
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-medium text-yak-slate dark:text-zinc-100">New task</h2>
                <button
                    type="button"
                    @click="newTaskOpen = false"
                    aria-label="Close"
                    class="text-zinc-400 hover:text-zinc-600 transition-colors"
                >
                    <flux:icon.x-mark class="size-5" />
                </button>
            </div>
            <livewire:tasks.create-task />
        </div>
    </div>
    </div>
</div>
