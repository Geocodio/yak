@props(['column', 'sort', 'direction'])

@php($active = $sort === $column)

<button
    type="button"
    wire:click="sortBy('{{ $column }}')"
    class="inline-flex items-center gap-1 uppercase tracking-wider transition-colors hover:text-zinc-800 dark:hover:text-zinc-200 {{ $active ? 'text-zinc-800 dark:text-zinc-200' : '' }}"
    aria-sort="{{ $active ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"
    data-testid="sort-{{ $column }}"
>
    {{ $slot }}
    @if($active)
        @if($direction === 'asc')
            <flux:icon.chevron-up class="!size-3" />
        @else
            <flux:icon.chevron-down class="!size-3" />
        @endif
    @endif
</button>
