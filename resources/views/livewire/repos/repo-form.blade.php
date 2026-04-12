<div>
    <div class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">
        <flux:link :href="route('repos')" wire:navigate class="text-accent font-medium">Repositories</flux:link>
        <span class="mx-1.5 text-zinc-400">/</span>
        {{ $this->isEditing ? 'Edit' : 'Add New' }}
    </div>

    <flux:heading size="xl" class="mb-6">{{ $this->isEditing ? 'Edit Repository' : 'Add Repository' }}</flux:heading>

    <form wire:submit="save">
        {{-- Basics --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-4">{{ __('Basics') }}</flux:heading>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <flux:input wire:model="slug" label="Slug" description="Auto-generated from name. Must be unique." />
                <flux:input wire:model.live.debounce.300ms="name" label="Display Name" />
                <div class="md:col-span-2">
                    <flux:input wire:model="git_url" label="Git URL" placeholder="https://github.com/your-org/my-project.git" description="HTTPS clone URL. Authenticated via the GitHub App." />
                </div>
                <div class="md:col-span-2">
                    <flux:input wire:model="path" label="Path" placeholder="/home/yak/repos/my-project" description="Auto-filled from slug. The repo will be cloned here." />
                </div>
                <flux:input wire:model="default_branch" label="Default Branch" />
                <div>
                    <flux:switch wire:model="is_active" label="Active" description="Enabled" />
                </div>
                <div>
                    <flux:switch wire:model="is_default" label="Default Repository" description="Only one repository can be the default." />
                </div>
            </div>
        </div>

        {{-- Integration --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-4">{{ __('Integration') }}</flux:heading>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <flux:select wire:model="ci_system" label="CI System">
                    <flux:select.option value="github_actions">GitHub Actions</flux:select.option>
                    <flux:select.option value="drone">Drone</flux:select.option>
                </flux:select>
                <flux:input wire:model="sentry_project" label="Sentry Project Slug" placeholder="my-project" description="Maps incoming Sentry webhooks to this repository." />
            </div>
        </div>

        {{-- Notes --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="mb-4">{{ __('Notes') }}</flux:heading>
            <flux:textarea wire:model="notes" label="Notes" placeholder="Operational context, infrastructure requirements, gotchas..." description="Shown in the dashboard only. Never sent to Claude." rows="4" />
        </div>

        @unless($this->isEditing)
            <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    <strong class="text-blue-600 dark:text-blue-400">After saving,</strong> Yak automatically dispatches a setup task — Claude Code reads the repo's README and CLAUDE.md, sets up the dev environment, and verifies everything works.
                </p>
            </div>
        @endunless

        {{-- Actions --}}
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save Repository') }}</flux:button>

                @if($this->isEditing)
                    <flux:button variant="filled" wire:click.prevent="rerunSetup">{{ __('Re-run Setup') }}</flux:button>
                @endif
            </div>

            <flux:button variant="ghost" :href="route('repos')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>

    @if($this->isEditing)
        <div class="mt-12 rounded-xl border border-red-200 bg-white p-6 shadow-sm dark:border-red-800 dark:bg-zinc-900">
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
