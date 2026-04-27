<div wire:poll.{{ $this->pollInterval }}>
    <flux:heading size="xl">{{ $deployment->hostname }}</flux:heading>
    <flux:subheading>{{ $deployment->repository->slug }} / {{ $deployment->branch_name }}</flux:subheading>

    @if (session('status'))
        <flux:callout variant="success" class="my-4">{{ session('status') }}</flux:callout>
    @endif

    <flux:card class="my-4">
        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Status</dt>
            <dd>
                <flux:badge :color="match($deployment->status) {
                    App\Enums\DeploymentStatus::Running => 'green',
                    App\Enums\DeploymentStatus::Failed => 'red',
                    App\Enums\DeploymentStatus::Hibernated => 'amber',
                    default => 'zinc',
                }">{{ $deployment->status->value }}</flux:badge>
            </dd>

            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Current commit</dt>
            <dd class="font-mono">{{ substr($deployment->current_commit_sha ?? '', 0, 10) ?: '—' }}</dd>

            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Template version</dt>
            <dd>v{{ $deployment->template_version }} (repo current: v{{ $deployment->repository->current_template_version }})</dd>

            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Last accessed</dt>
            <dd>{{ $deployment->last_accessed_at?->diffForHumans() ?? 'Never' }}</dd>

            @if ($deployment->status === \App\Enums\DeploymentStatus::Failed && $deployment->failure_reason)
                <dt class="font-medium text-zinc-600 dark:text-zinc-400">Failure</dt>
                <dd class="text-red-600">{{ $deployment->failure_reason }}</dd>
            @endif
        </dl>
    </flux:card>

    <div class="flex gap-2 my-4">
        <flux:button :href="'https://' . $deployment->hostname" target="_blank" rel="noopener">Open preview</flux:button>
        <flux:button wire:click="rebuild" variant="subtle"
            wire:confirm="Rebuild from latest template? Container data will be lost.">
            Rebuild from latest template
        </flux:button>
        <flux:button wire:click="destroy" variant="danger"
            wire:confirm="Destroy this deployment?">
            Destroy
        </flux:button>
    </div>

    <div x-data="activityFollow()" class="mt-6">
        <div class="mb-2 flex items-center justify-between">
            <flux:heading size="lg">Activity log</flux:heading>
            <div class="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ $this->recentLogs->count() }} entries</span>
                <button
                    type="button"
                    @click="jumpToLatest()"
                    x-show="!following"
                    x-cloak
                    class="rounded border border-zinc-300 bg-white px-2 py-0.5 font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                >
                    Jump to latest
                </button>
            </div>
        </div>
        <div
            x-ref="logList"
            wire:poll.{{ $this->pollInterval }}="$refresh"
            @scroll.passive="onScroll()"
            class="rounded border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 h-[70vh] min-h-[28rem] overflow-y-auto"
        >
            @forelse ($this->recentLogs as $log)
                <div class="flex gap-2 border-b border-zinc-200 px-3 py-2 text-sm last:border-b-0 dark:border-zinc-800">
                    <span class="shrink-0 font-mono text-xs text-zinc-500">{{ $log->created_at->format('H:i:s') }}</span>
                    @if ($log->phase)
                        <flux:badge size="sm" :color="match($log->phase) {
                            'fetch', 'checkout' => 'zinc',
                            'refresh' => 'blue',
                            'cold_start' => 'purple',
                            'reclaim_workspace' => 'amber',
                            'lifecycle' => 'amber',
                            default => 'zinc',
                        }">{{ $log->phase }}</flux:badge>
                    @endif
                    <pre class="{{ $log->level === 'error' ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }} whitespace-pre-wrap break-words font-mono text-xs flex-1">{{ $log->message }}@php $output = $log->output(); @endphp@if ($output !== '')
{{ $output }}@endif</pre>
                </div>
            @empty
                <div class="p-3 text-sm text-zinc-500">No activity yet.</div>
            @endforelse
        </div>
    </div>
</div>
