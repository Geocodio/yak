<div wire:poll.15s>
    <div
        x-data="{
            newTaskOpen: false,
            hoverPreview: null,
            showPreview(src) {
                if (! src || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    return;
                }

                this.hoverPreview = src;
            },
            hidePreview() {
                this.hoverPreview = null;
            },
        }"
    >
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

        @if($tab === 'tasks')
            <div class="w-full sm:w-40">
                <flux:select wire:model.live="pr" size="sm" aria-label="Filter by PR state">
                    <flux:select.option value="">All PRs</flux:select.option>
                    <flux:select.option value="open">Open</flux:select.option>
                    <flux:select.option value="merged">Merged</flux:select.option>
                    <flux:select.option value="closed">Closed</flux:select.option>
                    <flux:select.option value="none">No PR</flux:select.option>
                </flux:select>
            </div>
        @endif

        @if($status !== '' || $source !== '' || $repo !== '' || $pr !== '')
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
            @php($headerClasses = 'px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400')
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <th class="{{ $headerClasses }}"><x-tasks.sort-header column="status" :sort="$sort" :direction="$direction"><span class="sr-only">Status</span></x-tasks.sort-header></th>
                    <th class="{{ $headerClasses }}"><x-tasks.sort-header column="source" :sort="$sort" :direction="$direction">Source</x-tasks.sort-header></th>
                    <th class="{{ $headerClasses }}"><x-tasks.sort-header column="author_name" :sort="$sort" :direction="$direction">By</x-tasks.sort-header></th>
                    <th class="{{ $headerClasses }}"><x-tasks.sort-header column="repo" :sort="$sort" :direction="$direction">Repo</x-tasks.sort-header></th>
                    <th class="{{ $headerClasses }}">Description</th>
                    <th class="{{ $headerClasses }}">PR</th>
                    <th class="{{ $headerClasses }}"><x-tasks.sort-header column="created_at" :sort="$sort" :direction="$direction">Created</x-tasks.sort-header></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($this->tasks as $task)
                    @php($children = $task->branch_name ? ($this->descendantsByBranch[$task->branch_name] ?? collect()) : collect())
                    @php($preview = $this->previewsByTask[$task->id] ?? null)
                    @php($prState = $task->prState())
                    @php($deployment = $this->deploymentFor($task))
                    {{-- `transform: translateZ(0)` forces Safari to treat the <tr> as a containing block for
                         the stretched-link anchor below. Without it, Safari leaks the anchor up to the viewport,
                         every row's anchor stacks, and every tap hits the last row.

                         Rows with a preview GIF hand its URL to the shared `hoverPreview` overlay on hover.
                         The overlay is Alpine-only so the 15s poll cannot reset the GIF mid-hover, and the
                         GIF is only fetched once a row is actually hovered. --}}
                    <tr
                        wire:key="task-{{ $task->id }}"
                        class="relative h-14 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                        style="transform: translateZ(0)"
                        @if($preview && $preview['gif'])
                            data-preview-src="{{ $preview['gif'] }}"
                            data-testid="task-row-preview-{{ $task->id }}"
                            @mouseenter="showPreview($el.dataset.previewSrc)"
                            @mouseleave="hidePreview()"
                        @endif
                    >
                        <td class="w-px whitespace-nowrap px-3 py-2 sm:px-5">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="absolute inset-0" aria-label="Open task {{ $task->external_id ?? $task->id }}"></a>
                            <flux:tooltip :content="ucfirst(\App\Livewire\Tasks\TaskList::statusLabel($task->status))">
                                <span class="relative inline-flex items-center justify-center p-1" data-testid="status-dot-{{ $task->id }}">
                                    <span class="block size-2.5 rounded-full {{ \App\Livewire\Tasks\TaskList::statusDotClasses($task->status) }}"></span>
                                    <span class="sr-only">{{ \App\Livewire\Tasks\TaskList::statusLabel($task->status) }}</span>
                                </span>
                            </flux:tooltip>
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
                        <td class="max-w-[10rem] truncate px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300" data-testid="task-author-{{ $task->id }}">
                            {{ $task->author_name ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">
                            @if($task->pr_url)
                                <a href="{{ $task->pr_url }}" target="_blank" rel="noopener noreferrer" class="relative font-medium text-accent hover:underline" title="Open the PR on GitHub">{{ $task->repo }}</a>
                            @elseif($task->repository)
                                <a href="{{ route('repos.edit', $task->repository) }}" wire:navigate class="relative font-medium text-accent hover:underline">{{ $task->repo }}</a>
                            @else
                                {{ $task->repo ?? '—' }}
                            @endif
                        </td>
                        {{-- `w-full max-w-0` lets this cell absorb the remaining width in an auto-layout table
                             while still truncating, so the fixed columns to its right never get clipped. --}}
                        <td class="w-full max-w-0 px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">
                            <div class="flex min-w-0 items-center gap-2">
                                @if($preview)
                                    <a
                                        href="{{ route('tasks.show', $task) }}?t=0"
                                        wire:navigate
                                        wire:key="preview-{{ $task->id }}"
                                        class="relative shrink-0"
                                        aria-label="Open the walkthrough for task {{ $task->external_id ?? $task->id }}"
                                        data-testid="task-preview-{{ $task->id }}"
                                    >
                                        <img src="{{ $preview['poster'] }}" alt="" loading="lazy" class="h-8 w-14 rounded-md border border-zinc-200 object-cover dark:border-zinc-700" />
                                    </a>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate">{{ \Illuminate\Support\Str::limit($task->description, 140) }}</span>
                                        @if($children->isNotEmpty())
                                            <span class="shrink-0 rounded-full bg-[rgba(212,145,94,0.12)] px-2 py-0.5 text-[10px] font-semibold text-[#d4915e]">{{ $children->count() }} follow-ups</span>
                                        @endif
                                    </div>
                                    @if($task->external_id)
                                        <div class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">
                                            @if($task->external_url)
                                                <a href="{{ $task->external_url }}" target="_blank" rel="noopener noreferrer" class="relative hover:text-accent hover:underline">{{ $task->external_id }}</a>
                                            @else
                                                {{ $task->external_id }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 sm:px-5">
                            @if($prState !== null)
                                <a
                                    href="{{ $task->pr_url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="relative inline-block rounded-lg px-2.5 py-0.5 text-xs font-medium hover:underline {{ \App\Livewire\Tasks\TaskList::prStateBadgeClasses($prState) }}"
                                    data-testid="pr-state-{{ $task->id }}"
                                >{{ ucfirst($prState) }}</a>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                            @if($deployment)
                                <flux:tooltip content="Open the branch preview ({{ ucfirst($deployment->status->value) }})">
                                    <a
                                        href="https://{{ $deployment->hostname }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="relative ml-1.5 inline-flex align-middle text-zinc-400 hover:text-accent"
                                        aria-label="Open the branch preview for task {{ $task->external_id ?? $task->id }}"
                                        data-testid="task-preview-link-{{ $task->id }}"
                                    >
                                        <flux:icon.globe-alt class="!size-4" />
                                    </a>
                                </flux:tooltip>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">
                            <flux:tooltip content="Created {{ $task->created_at?->format('M j, Y H:i') }} · Updated {{ $task->updated_at?->format('M j, Y H:i') }} · Ran for {{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }}">
                                <span class="relative">{{ \App\Livewire\Tasks\TaskList::formatAge($task->created_at) }}</span>
                            </flux:tooltip>
                        </td>
                    </tr>
                    @foreach($children as $child)
                        <tr wire:key="child-{{ $child->id }}" class="relative h-12 bg-zinc-50/60 transition-colors hover:bg-zinc-100/60 dark:bg-zinc-800/30 dark:hover:bg-zinc-800/60" style="transform: translateZ(0)">
                            <td class="w-px whitespace-nowrap py-2 pl-8 pr-3 sm:pr-5">
                                <a href="{{ route('tasks.show', $child) }}" wire:navigate class="absolute inset-0" aria-label="Open task {{ $child->external_id ?? $child->id }}"></a>
                                <flux:tooltip :content="ucfirst(\App\Livewire\Tasks\TaskList::statusLabel($child->status))">
                                    <span class="relative inline-flex items-center justify-center p-1">
                                        <span class="block size-2.5 rounded-full {{ \App\Livewire\Tasks\TaskList::statusDotClasses($child->status) }}"></span>
                                        <span class="sr-only">{{ \App\Livewire\Tasks\TaskList::statusLabel($child->status) }}</span>
                                    </span>
                                </flux:tooltip>
                            </td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">—</td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">{{ $child->author_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">—</td>
                            <td class="w-full max-w-0 px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">
                                <div class="truncate">{{ \Illuminate\Support\Str::limit($child->description, 140) }}</div>
                                @if($child->external_id)
                                    <div class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">{{ $child->external_id }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">—</td>
                            <td class="whitespace-nowrap px-3 py-2 text-zinc-400 sm:px-5 dark:text-zinc-600">
                                <flux:tooltip content="Created {{ $child->created_at?->format('M j, Y H:i') }} · Ran for {{ \App\Livewire\Tasks\TaskList::formatDuration($child->duration_ms) }}">
                                    <span class="relative">{{ \App\Livewire\Tasks\TaskList::formatAge($child->created_at) }}</span>
                                </flux:tooltip>
                            </td>
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

    {{-- Floating walkthrough preview. Pointer events are off so the hovered row keeps its hover state
         while the GIF is shown as large as the viewport allows. Teleported to <body> so `fixed` is
         measured against the viewport rather than whichever layout ancestor forms a containing block. --}}
    <template x-teleport="body">
    <div
        x-show="hoverPreview !== null"
        x-cloak
        x-transition.opacity.duration.150ms
        class="pointer-events-none fixed inset-0 z-[60] flex items-center justify-center p-6"
        style="display:none"
        data-testid="hover-preview-overlay"
        aria-hidden="true"
    >
        <img
            :src="hoverPreview ?? ''"
            alt=""
            {{-- Preview GIFs are encoded at 720px wide, so the height is derived from the viewport and the
                 width follows the GIF's own aspect ratio. This upscales it to fill the screen without distortion. --}}
            style="height: min(85vh, calc(90vw * 9 / 16)); width: auto;"
            class="max-w-[90vw] rounded-xl border border-zinc-200 bg-white object-contain shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
            data-testid="hover-preview-image"
        />
    </div>
    </template>

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
