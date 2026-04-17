<div>
    <h1 class="text-2xl font-semibold text-yak-slate mb-2">{{ $repoSlug }}#{{ $prNumber }}</h1>
    <p class="text-yak-slate/70 mb-5">
        <a href="https://github.com/{{ $repoSlug }}/pull/{{ $prNumber }}" target="_blank" class="text-yak-orange hover:underline">
            View on GitHub
        </a>
    </p>

    <div class="mb-5">
        <flux:button wire:click="rerunReview" variant="outline">
            Re-run review
        </flux:button>
    </div>

    @if($reviews->isEmpty())
        <div class="rounded-[20px] border border-yak-tan/40 bg-yak-cream-dark/40 p-8 text-center">
            <p class="text-yak-slate">No reviews yet for this PR.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($reviews as $review)
                <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <flux:badge size="sm" :variant="$review->review_scope === 'full' ? 'outline' : 'primary'">
                                {{ $review->review_scope }}
                            </flux:badge>
                            @if($review->dismissed_at)
                                <flux:badge size="sm" variant="ghost">Dismissed</flux:badge>
                            @endif
                            <span class="text-sm text-yak-slate/70">
                                {{ $review->submitted_at?->diffForHumans() }}
                            </span>
                        </div>
                        @if($review->task)
                            <a href="{{ route('tasks.show', $review->task) }}" class="text-sm text-yak-orange hover:underline">
                                View task →
                            </a>
                        @endif
                    </div>
                    <div class="text-sm text-yak-slate">
                        <strong>{{ $review->verdict }}</strong>
                        — {{ $review->comments->count() }} finding{{ $review->comments->count() === 1 ? '' : 's' }}
                    </div>
                    @if($review->summary)
                        <p class="text-sm text-yak-slate/80 mt-2">{{ $review->summary }}</p>
                    @endif
                    <div class="text-xs font-mono text-yak-slate/60 mt-2">
                        sha: {{ $review->commit_sha_reviewed }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
