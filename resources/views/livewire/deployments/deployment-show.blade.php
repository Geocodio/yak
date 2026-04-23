<div wire:poll.10s>
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

            @if ($deployment->failure_reason)
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

    <flux:heading size="lg" class="mt-6">Recent activity</flux:heading>
    <ul class="my-2 space-y-1 font-mono text-sm">
        @foreach ($this->recentLogs as $log)
            <li>
                <span class="text-zinc-500">[{{ $log->created_at->format('H:i:s') }}]</span>
                [{{ $log->level }}]
                {{ $log->message }}
            </li>
        @endforeach
    </ul>
</div>
