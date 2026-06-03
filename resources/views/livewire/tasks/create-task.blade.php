<div data-testid="create-task-form">
    <form wire:submit="save">
        {{-- Repo picker --}}
        <div class="mb-4">
            <flux:select wire:model="repo" label="Repository" placeholder="Choose a repository…">
                @foreach($this->repos as $r)
                    <flux:select.option value="{{ $r->slug }}">{{ $r->slug }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('repo')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Fix / Research toggle --}}
        <div class="mb-4">
            <label class="mb-2 block text-sm font-medium text-yak-slate">Mode</label>
            <div class="flex gap-2">
                <button
                    type="button"
                    wire:click="$set('taskMode', 'fix')"
                    data-testid="mode-fix"
                    class="rounded-lg border px-4 py-1.5 text-sm font-medium transition-colors {{ $taskMode === 'fix' ? 'border-yak-orange bg-yak-orange/10 text-yak-orange' : 'border-yak-tan/50 bg-transparent text-yak-blue hover:border-yak-orange/50 hover:text-yak-orange' }}"
                >
                    Fix
                </button>
                <button
                    type="button"
                    wire:click="$set('taskMode', 'research')"
                    data-testid="mode-research"
                    class="rounded-lg border px-4 py-1.5 text-sm font-medium transition-colors {{ $taskMode === 'research' ? 'border-yak-orange bg-yak-orange/10 text-yak-orange' : 'border-yak-tan/50 bg-transparent text-yak-blue hover:border-yak-orange/50 hover:text-yak-orange' }}"
                >
                    Research
                </button>
            </div>
            @error('taskMode')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Description --}}
        <div class="mb-5">
            <flux:textarea
                wire:model="description"
                label="Description"
                placeholder="Describe what you'd like Yak to do…"
                rows="4"
                data-testid="new-task-description"
            />
            @error('description')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Submit --}}
        <flux:button type="submit" variant="primary" data-testid="new-task-submit">Start task</flux:button>
    </form>
</div>
