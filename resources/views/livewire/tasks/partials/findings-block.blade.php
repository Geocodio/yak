{{--
    Review findings: verdict badge, severity counts, and one row per
    review comment. Rendered inside the yak thread entry for Review-mode
    tasks in place of the old "Review output" / "Review preview" /
    "Findings" cards.

    Expects: $review (App\Models\PrReview, with comments loaded).
--}}
@php
    $mustFixCount = $review->comments->where('severity', 'must_fix')->count();
    $shouldFixCount = $review->comments->where('severity', 'should_fix')->count();
    $considerCount = $review->comments->where('severity', 'consider')->count();
@endphp
<div class="mt-3 rounded-xl border border-[rgba(200,184,154,0.3)] bg-[rgba(245,240,232,0.4)] p-4" data-testid="findings-block">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:badge size="sm" :variant="str_contains(strtolower((string) $review->verdict), 'approve') ? 'primary' : 'outline'" data-testid="findings-verdict">
            {{ $review->verdict ?? 'No verdict' }}
        </flux:badge>
        <span class="text-xs text-yak-blue" data-testid="findings-counts">
            {{ $mustFixCount }} must-fix &middot; {{ $shouldFixCount }} should-fix &middot; {{ $considerCount }} consider
        </span>
    </div>

    <x-markdown :text="$review->summary" class="mt-3 leading-relaxed" />

    @if($review->comments->isNotEmpty())
        <div class="mt-3 flex flex-col gap-2">
            @foreach($review->comments as $comment)
                <div class="rounded-lg border border-[rgba(200,184,154,0.25)] bg-white p-3" x-data="{ open: false }" data-testid="finding-row">
                    <button type="button" class="flex w-full items-center justify-between gap-2 text-left" @click="open = !open">
                        <span class="flex min-w-0 items-center gap-2">
                            <flux:badge size="sm" :variant="$comment->severity === 'must_fix' ? 'danger' : ($comment->severity === 'should_fix' ? 'warning' : 'outline')">
                                {{ str_replace('_', ' ', $comment->severity) }}
                            </flux:badge>
                            <code class="truncate font-mono text-xs text-yak-blue">{{ $comment->file_path }}:{{ $comment->line_number }}</code>
                            <span class="shrink-0 text-xs text-yak-tan">{{ $comment->category }}</span>
                        </span>
                        <flux:icon.chevron-right class="!size-3.5 shrink-0 text-yak-tan transition-transform duration-150" x-bind:class="open ? 'rotate-90' : ''" />
                    </button>
                    <div x-show="open" x-cloak>
                        <x-markdown :text="$comment->body" class="mt-2 leading-relaxed" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
