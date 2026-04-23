<div wire:poll.15s>
    <flux:heading size="xl">Deployments</flux:heading>

    <div class="flex gap-3 my-4">
        <flux:select wire:model.live="statusFilter" class="w-48">
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="running">Running</flux:select.option>
            <flux:select.option value="hibernated">Hibernated</flux:select.option>
            <flux:select.option value="failed">Failed</flux:select.option>
            <flux:select.option value="all">All</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Repository</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Branch</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Status</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Last accessed</th>
                    <th class="px-3 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 sm:px-5 dark:text-zinc-400">Preview URL</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->deployments as $d)
                    <tr wire:key="deployment-{{ $d->id }}" class="transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3 py-2 text-zinc-700 sm:px-5 dark:text-zinc-300">{{ $d->repository->slug }}</td>
                        <td class="px-3 py-2 sm:px-5">
                            <a href="{{ route('deployments.show', $d) }}" wire:navigate class="font-medium text-yak-orange hover:underline">
                                {{ $d->branch_name }}
                            </a>
                        </td>
                        <td class="px-3 py-2 sm:px-5">
                            <flux:badge :color="match($d->status) {
                                App\Enums\DeploymentStatus::Running => 'green',
                                App\Enums\DeploymentStatus::Failed => 'red',
                                App\Enums\DeploymentStatus::Hibernated => 'amber',
                                default => 'zinc',
                            }">{{ $d->status->value }}</flux:badge>
                        </td>
                        <td class="px-3 py-2 text-zinc-500 sm:px-5 dark:text-zinc-400">{{ $d->last_accessed_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-3 py-2 sm:px-5">
                            <a href="https://{{ $d->hostname }}" target="_blank" rel="noopener" class="text-yak-orange hover:underline">
                                {{ $d->hostname }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-16 text-center text-zinc-500 sm:px-5 dark:text-zinc-400">
                            No deployments found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->deployments->links() }}</div>
</div>
