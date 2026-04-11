@props([
    'sidebar' => false,
])

@if($sidebar)
    <a {{ $attributes }} class="block px-6 py-6">
        <h1 class="font-serif text-[42px] text-yak-slate tracking-tight leading-none">
            Y<span class="italic text-yak-orange">a</span>k
        </h1>
    </a>
@else
    <a {{ $attributes }} class="inline-flex items-center">
        <span class="font-serif text-3xl text-yak-slate tracking-tight">
            Y<span class="italic text-yak-orange">a</span>k
        </span>
    </a>
@endif
