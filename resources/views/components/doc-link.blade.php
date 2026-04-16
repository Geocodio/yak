@props([
    'anchor' => 'home',
    'icon' => true,
])

@php
    $url = \App\Support\Docs::url($anchor);
@endphp

<a
    href="{{ $url }}"
    target="_blank"
    rel="noopener noreferrer"
    {{ $attributes->class(['inline-flex items-center gap-1 text-yak-orange hover:text-yak-orange-warm font-medium transition-colors']) }}
>
    <span>{{ $slot }}</span>
    @if($icon)
        <flux:icon.arrow-top-right-on-square class="!size-3.5 opacity-70" />
    @endif
</a>
