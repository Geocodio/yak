<div>
    <div class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">
        <flux:link :href="route('repos')" wire:navigate class="text-accent font-medium">Repositories</flux:link>
        <span class="mx-1.5 text-zinc-400">/</span>
        {{ $this->isEditing ? 'Edit' : 'Add New' }}
    </div>

    <div class="mb-6 flex items-center gap-3">
        <flux:heading size="xl">{{ $this->isEditing ? 'Edit Repository' : 'Add Repository' }}</flux:heading>
        @if($this->isEditing && $repository->githubUrl())
            <a href="{{ $repository->githubUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-medium text-yak-blue hover:text-yak-orange" title="Open on GitHub">
                <flux:icon.arrow-top-right-on-square class="!size-3.5" />
                <span>Open on GitHub</span>
            </a>
        @endif
    </div>

    <form wire:submit="save">
        @unless($this->isEditing)
            {{-- GitHub Repository Picker (create mode) --}}
            <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" class="mb-4">{{ __('GitHub Repository') }}</flux:heading>

                @if($selected_github_repo)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-center gap-3">
                            <flux:icon name="folder" variant="mini" class="text-zinc-500" />
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selected_github_repo }}</p>
                                <p class="text-sm text-zinc-500">{{ $default_branch }} branch</p>
                            </div>
                        </div>
                        <flux:button variant="ghost" size="sm" wire:click="clearSelectedRepo">{{ __('Change') }}</flux:button>
                    </div>
                @else
                    <div x-data="{ open: false, highlightedIndex: -1 }" @click.away="open = false" class="relative">
                        <flux:input
                            wire:model.live.debounce.300ms="github_search"
                            placeholder="Search your GitHub repositories..."
                            icon="magnifying-glass"
                            @focus="open = true"
                            @keydown.escape="open = false"
                            @keydown.arrow-down.prevent="highlightedIndex = Math.min(highlightedIndex + 1, {{ count($this->filteredGitHubRepos) - 1 }})"
                            @keydown.arrow-up.prevent="highlightedIndex = Math.max(highlightedIndex - 1, 0)"
                            @keydown.enter.prevent="if (highlightedIndex >= 0) { $refs['repo-' + highlightedIndex]?.click() }"
                        />
                        @if(count($this->filteredGitHubRepos) > 0)
                            <div
                                x-show="open"
                                x-cloak
                                class="absolute z-20 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <ul class="max-h-64 overflow-y-auto py-1">
                                    @foreach($this->filteredGitHubRepos as $index => $repo)
                                        <li>
                                            <button
                                                type="button"
                                                x-ref="repo-{{ $index }}"
                                                wire:click="selectGitHubRepo('{{ $repo['full_name'] }}')"
                                                @click="open = false"
                                                :class="highlightedIndex === {{ $index }} ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
                                                @mouseenter="highlightedIndex = {{ $index }}"
                                                class="flex w-full items-center justify-between px-4 py-2.5 text-left hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                            >
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $repo['name'] }}</span>
                                                @if($repo['pushed_at'])
                                                    <span class="text-xs text-zinc-400">{{ \Carbon\Carbon::parse($repo['pushed_at'])->diffForHumans() }}</span>
                                                @endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="border-t border-zinc-200 px-4 py-2 dark:border-zinc-700">
                                    <p class="text-xs text-zinc-400">Can't find your repository? Ensure it's authorized in <a href="https://github.com/settings/installations" target="_blank" class="text-accent underline">GitHub</a>.</p>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endunless

        {{-- Configuration --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-4">{{ $this->isEditing ? __('Basics') : __('Configuration') }}</flux:heading>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                @if($this->isEditing)
                    <div>
                        <flux:input wire:model="slug" label="Slug" disabled description="Auto-generated. Cannot be changed." />
                    </div>
                @endif
                <flux:input wire:model="name" label="Display Name" />
                <div class="md:col-span-2">
                    <flux:textarea wire:model="description" label="Description" description="One-line description of what this repo does. Used to route natural-language tasks to the right repo." rows="2" />
                </div>
                @if($this->isEditing)
                    <div class="md:col-span-2">
                        <flux:textarea
                            wire:model="agent_instructions"
                            label="Agent instructions"
                            description="Freeform notes appended to every task's system prompt for this repo. Use this for repo-specific quirks that override the global rules — e.g. 'Don't run the full test suite locally — CI handles it (needs 800GB of data).' or 'Use pnpm, not npm.'"
                            placeholder="Leave empty to use only the global rules."
                            rows="5"
                        />
                    </div>
                @endif
                @if($this->isEditing)
                    <div class="md:col-span-2">
                        <flux:input wire:model="git_url" label="Git URL" description="HTTPS clone URL. Authenticated via the GitHub App." />
                    </div>
                @endif
                <flux:input wire:model="default_branch" label="Default Branch" />
                @if($this->isEditing)
                    <flux:select wire:model="ci_system" label="CI System">
                        <flux:select.option value="none">None</flux:select.option>
                        <flux:select.option value="github_actions">GitHub Actions</flux:select.option>
                        <flux:select.option value="drone">Drone</flux:select.option>
                    </flux:select>
                @elseif($selected_github_repo)
                    <div>
                        <flux:field>
                            <flux:label>CI System</flux:label>
                            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                @if($detected_ci_system === 'drone')
                                    <flux:icon.check-circle variant="mini" class="text-emerald-500" />
                                    <span>Drone detected (<code class="text-xs">.drone.yml</code>)</span>
                                @elseif($detected_ci_system === 'github_actions')
                                    <flux:icon.check-circle variant="mini" class="text-emerald-500" />
                                    <span>GitHub Actions detected (<code class="text-xs">.github/workflows</code>)</span>
                                @else
                                    <flux:icon.minus-circle variant="mini" class="text-zinc-400" />
                                    <span>No CI detected — change later if needed.</span>
                                @endif
                            </div>
                        </flux:field>
                    </div>
                @endif
                @if(count($sentry_projects) > 0)
                    <flux:select wire:model="sentry_project" label="Sentry Project" placeholder="None">
                        <flux:select.option value="">None</flux:select.option>
                        @foreach($sentry_projects as $project)
                            <flux:select.option value="{{ $project['slug'] }}">{{ $project['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:input
                        wire:model="sentry_project"
                        label="Sentry Project Slug"
                        placeholder="my-project"
                        :description="$this->sentryProjectHint"
                    />
                @endif
                <div>
                    <flux:switch wire:model="is_default" label="Default Repository" description="Only one repository can be the default." />
                </div>
                @if($this->isEditing)
                    <div>
                        <flux:switch wire:model="is_active" label="Active" description="Enabled" />
                    </div>
                @endif
            </div>
        </div>

        @unless($this->isEditing)
            <div class="mb-6 flex items-start gap-3 rounded-[20px] border border-yak-tan/40 bg-yak-cream-dark/60 p-4">
                <div class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-yak-orange/15 text-yak-orange">
                    <flux:icon.sparkles variant="mini" class="size-4" />
                </div>
                <p class="text-sm leading-relaxed text-yak-slate">
                    <span class="font-medium text-yak-orange">After saving,</span>
                    Yak dispatches a setup task — Claude Code reads your README and CLAUDE.md, prepares the dev environment, and verifies everything works.
                </p>
            </div>
        @endunless

        {{-- Actions --}}
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ $this->isEditing ? __('Save Repository') : __('Add Repository') }}</flux:button>

                @if($this->isEditing)
                    <flux:button
                        variant="filled"
                        wire:click.prevent="rerunSetup"
                        wire:loading.attr="disabled"
                        wire:target="rerunSetup"
                        icon="arrow-path"
                    >
                        <span wire:loading.remove wire:target="rerunSetup">{{ __('Re-run Setup') }}</span>
                        <span wire:loading wire:target="rerunSetup">{{ __('Dispatching…') }}</span>
                    </flux:button>
                @endif
            </div>

            <flux:button variant="ghost" :href="route('repos')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>

    @if($this->isEditing)
        @php($currentBaseVersion = (int) config('yak.sandbox.base_version', 1))
        <div class="mt-10 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-3">{{ __('Sandbox Template') }}</flux:heading>
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Snapshot') }}</dt>
                    <dd class="mt-1 font-mono text-zinc-700 dark:text-zinc-300">{{ $repository->sandbox_snapshot ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('yak-base Version') }}</dt>
                    <dd class="mt-1 flex items-center gap-2">
                        @if($repository->sandbox_base_version === null)
                            <span class="text-zinc-500">— (not provisioned)</span>
                        @elseif((int) $repository->sandbox_base_version === $currentBaseVersion)
                            <span class="inline-block rounded-lg bg-[rgba(122,140,94,0.12)] px-2.5 py-1 text-xs font-medium text-[#7a8c5e] tabular-nums">v{{ $repository->sandbox_base_version }}</span>
                            <span class="text-xs text-zinc-500">up to date</span>
                        @else
                            <span class="inline-block rounded-lg bg-[rgba(200,184,154,0.12)] px-2.5 py-1 text-xs font-medium text-[#c8b89a] tabular-nums">v{{ $repository->sandbox_base_version }}</span>
                            <span class="text-xs text-zinc-500">current yak-base is <span class="font-medium text-zinc-700 dark:text-zinc-300">v{{ $currentBaseVersion }}</span> — next task run re-provisions automatically</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900" data-testid="setup-history">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Setup History') }}</flux:heading>
                @if($this->setupTasks->isNotEmpty())
                    <a href="{{ route('tasks', ['tab' => 'setup', 'repo' => $repository->slug]) }}" wire:navigate class="text-xs font-medium text-yak-orange hover:text-yak-orange-warm">View all</a>
                @endif
            </div>

            @if($this->setupTasks->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No setup runs yet.</p>
            @else
                <div class="-mx-4 overflow-hidden sm:-mx-6">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-6 dark:text-zinc-400">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-6 dark:text-zinc-400">ID</th>
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-6 dark:text-zinc-400">Started</th>
                                <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-6 dark:text-zinc-400">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach($this->setupTasks as $setupTask)
                                <tr wire:key="setup-task-{{ $setupTask->id }}" class="h-12 cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50" onclick="window.location='{{ route('tasks.show', $setupTask) }}'">
                                    <td class="px-4 py-2 sm:px-6">
                                        <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Tasks\TaskList::statusBadgeClasses($setupTask->status) }}">
                                            {{ str_replace('_', ' ', $setupTask->status->value) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 sm:px-6">
                                        <a href="{{ route('tasks.show', $setupTask) }}" wire:navigate class="font-medium text-accent hover:underline">{{ $setupTask->external_id }}</a>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 sm:px-6 dark:text-zinc-400">{{ $setupTask->created_at->diffForHumans() }}</td>
                                    <td class="px-4 py-2 text-zinc-500 sm:px-6 dark:text-zinc-400">{{ \App\Livewire\Tasks\TaskList::formatDuration($setupTask->duration_ms) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mt-6 rounded-xl border border-red-200 bg-white p-4 shadow-sm sm:p-6 dark:border-red-800 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-4 text-red-600 dark:text-red-400">{{ __('Danger Zone') }}</flux:heading>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        @if($this->canDelete)
                            Permanently delete this repository. This action cannot be undone.
                        @else
                            This repository has tasks and cannot be deleted. Deactivate it instead.
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    @if($repository->is_active)
                        <flux:button variant="ghost" wire:click="toggleActive">{{ __('Deactivate') }}</flux:button>
                    @else
                        <flux:button variant="ghost" wire:click="toggleActive">{{ __('Activate') }}</flux:button>
                    @endif
                    <flux:button variant="danger" wire:click="delete" wire:confirm="Are you sure you want to delete this repository?" :disabled="!$this->canDelete">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
