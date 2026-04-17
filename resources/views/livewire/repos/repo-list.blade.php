<div>
    <div class="mb-6 flex items-center justify-between">
        <flux:heading size="xl">{{ __('Repositories') }}</flux:heading>
        <flux:button variant="primary" :href="route('repos.create')" wire:navigate icon="plus">
            {{ __('Add Repository') }}
        </flux:button>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Slug</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Name</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">CI System</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Setup</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Base</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Status</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Default</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Tasks (Total)</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Tasks (7d)</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">PR Review</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($this->repositories as $repo)
                    {{-- translateZ forces Safari to treat the <tr> as a containing block; see task-list.blade.php --}}
                    <tr wire:key="repo-{{ $repo->id }}" class="relative h-14 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ !$repo->is_active ? 'opacity-60' : '' }}" style="transform: translateZ(0)">
                        <td class="px-3 py-2 sm:px-5">
                            <a href="{{ route('repos.edit', $repo) }}" wire:navigate class="absolute inset-0" aria-label="Open repository {{ $repo->slug }}"></a>
                            <span class="relative font-medium text-accent">{{ $repo->slug }}</span>
                        </td>
                        <td class="px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">{{ $repo->name }}</td>
                        <td class="px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">{{ \App\Livewire\Repos\RepoList::ciSystemLabel($repo->ci_system) }}</td>
                        <td class="px-3 py-2 sm:px-5">
                            <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Repos\RepoList::setupBadgeClasses($repo->setup_status) }}">
                                {{ ucfirst($repo->setup_status) }}
                            </span>
                        </td>
                        <td class="px-3 py-2 sm:px-5">
                            @php($currentBaseVersion = (int) config('yak.sandbox.base_version', 1))
                            @if($repo->sandbox_base_version === null)
                                <span class="text-xs text-zinc-400" title="No sandbox template provisioned yet">—</span>
                            @elseif((int) $repo->sandbox_base_version === $currentBaseVersion)
                                <span class="inline-block rounded-lg bg-[rgba(122,140,94,0.12)] px-2.5 py-1 text-xs font-medium text-[#7a8c5e] tabular-nums" title="Template matches current yak-base v{{ $currentBaseVersion }}">v{{ $repo->sandbox_base_version }}</span>
                            @else
                                <span class="inline-block rounded-lg bg-[rgba(200,184,154,0.12)] px-2.5 py-1 text-xs font-medium text-[#c8b89a] tabular-nums" title="Template drift — current yak-base is v{{ $currentBaseVersion }}. Next task run will re-provision.">v{{ $repo->sandbox_base_version }} → v{{ $currentBaseVersion }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 sm:px-5">
                            @if($repo->is_active)
                                <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]">Active</span>
                            @else
                                <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium bg-[rgba(200,184,154,0.12)] text-[#c8b89a]">Inactive</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 sm:px-5">
                            @if($repo->is_default)
                                <span class="text-accent">&#9733;</span>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 tabular-nums text-zinc-700 sm:px-5 dark:text-zinc-300 {{ $repo->tasks_count === 0 ? 'text-zinc-400!' : '' }}">{{ $repo->tasks_count }}</td>
                        <td class="px-3 py-2 tabular-nums text-zinc-700 sm:px-5 dark:text-zinc-300 {{ $repo->tasks_recent_count === 0 ? 'text-zinc-400!' : '' }}">{{ $repo->tasks_recent_count }}</td>
                        <td class="px-3 py-2 sm:px-5">
                            @if($repo->pr_review_enabled)
                                <div class="flex flex-col gap-0.5">
                                    <span class="inline-block rounded-lg px-2.5 py-1 text-xs font-medium bg-[rgba(122,140,94,0.12)] text-[#7a8c5e] w-fit">On</span>
                                    @if($repo->pr_reviews_30d_count > 0)
                                        <span class="text-xs text-zinc-500">{{ $repo->pr_reviews_30d_count }} in 30d</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-3 py-16 text-center text-zinc-500 sm:px-5 dark:text-zinc-400">
                            <div class="flex flex-col items-center gap-3">
                                <p class="text-sm">No repositories yet. Add one so Yak can clone and work on it.</p>
                                <x-doc-link anchor="repositories.adding" class="text-sm">Adding a repository</x-doc-link>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
