<div wire:poll.15s>
    <div class="mb-6 flex flex-wrap gap-3">
        <flux:select wire:model.live="status" class="min-w-40">
            <flux:select.option value="">All Statuses</flux:select.option>
            @foreach(\App\Enums\TaskStatus::cases() as $statusOption)
                <flux:select.option value="{{ $statusOption->value }}">{{ str_replace('_', ' ', ucfirst($statusOption->value)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="source" class="min-w-40">
            <flux:select.option value="">All Sources</flux:select.option>
            @foreach($this->sources as $sourceOption)
                <flux:select.option value="{{ $sourceOption }}">{{ ucfirst($sourceOption) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="repo" class="min-w-40">
            <flux:select.option value="">All Repos</flux:select.option>
            @foreach($this->repos as $repoOption)
                <flux:select.option value="{{ $repoOption }}">{{ $repoOption }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Source</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Repo</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">ID</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Description</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Duration</th>
                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">PR</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($this->tasks as $task)
                    <tr wire:key="task-{{ $task->id }}" class="h-14 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-5 py-2">
                            <span class="inline-block rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Tasks\TaskList::statusBadgeClasses($task->status) }}">
                                {{ str_replace('_', ' ', $task->status->value) }}
                            </span>
                        </td>
                        <td class="px-5 py-2">
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
                        <td class="px-5 py-2 text-zinc-700 dark:text-zinc-300">{{ $task->repo ?? '—' }}</td>
                        <td class="px-5 py-2">
                            @if($task->external_url)
                                <a href="{{ $task->external_url }}" target="_blank" class="font-medium text-accent hover:underline">{{ $task->external_id }}</a>
                            @else
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $task->external_id ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="max-w-xs truncate px-5 py-2 text-zinc-700 dark:text-zinc-300">{{ \Illuminate\Support\Str::limit($task->description, 60) }}</td>
                        <td class="px-5 py-2 text-zinc-500 dark:text-zinc-400">{{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }}</td>
                        <td class="px-5 py-2">
                            @if($task->pr_url)
                                <a href="{{ $task->pr_url }}" target="_blank" class="font-medium text-accent hover:underline">PR</a>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-zinc-500 dark:text-zinc-400">
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
