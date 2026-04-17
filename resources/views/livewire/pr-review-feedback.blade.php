<div>
    <h1 class="text-2xl font-semibold text-yak-slate mb-5">PR Reviews</h1>

    @if ($this->showIntro())
        <div class="mb-5 flex items-start gap-4 rounded-[20px] border border-yak-tan/40 bg-yak-cream-dark/60 p-4">
            <div class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-yak-orange/15 text-yak-orange">
                <flux:icon.sparkles variant="mini" class="size-4" />
            </div>
            <div class="flex-1 text-sm text-yak-slate">
                <div class="font-medium text-yak-orange mb-1">Yak now reviews your pull requests.</div>
                Turn on <em>PR Review</em> on a repository, then open a PR — Yak posts a line-level review with suggestion blocks. Reactions (👍/👎) are tracked here so you can see which kinds of feedback the team finds useful.
            </div>
            <button
                wire:click="dismissIntro"
                type="button"
                class="text-yak-slate/60 hover:text-yak-slate transition-colors"
                aria-label="Dismiss"
            >
                <flux:icon.x-mark class="size-5" />
            </button>
        </div>
    @endif

    @if ($stats['reviews'] === 0)
        <div class="rounded-[20px] border border-yak-tan/40 bg-yak-cream-dark/40 p-8 text-center">
            <flux:icon.chat-bubble-left-ellipsis class="mx-auto size-10 text-yak-slate/60" />
            <p class="mt-3 text-yak-slate">No Yak reviews yet. Enable PR review on a repository to get started.</p>
            <flux:button :href="route('repos')" variant="outline" class="mt-4">
                Manage repositories
            </flux:button>
        </div>
    @else
        {{-- Stats strip --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-5 mb-8">
            <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
                <div class="text-3xl font-semibold text-yak-slate">{{ $stats['reviews'] }}</div>
                <div class="text-sm text-yak-slate/70 mt-1">Reviews</div>
            </div>
            <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
                <div class="text-3xl font-semibold text-yak-slate">{{ $stats['suggestions'] }}</div>
                <div class="text-sm text-yak-slate/70 mt-1">Suggestions</div>
            </div>
            <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
                <div class="text-3xl font-semibold text-yak-slate">{{ $stats['thumbs_up_rate'] }}%</div>
                <div class="text-sm text-yak-slate/70 mt-1">👍 rate</div>
            </div>
            <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
                <div class="text-sm font-semibold text-yak-slate">{{ $stats['most_downvoted_category'] ?? '—' }}</div>
                <div class="text-sm text-yak-slate/70 mt-1">Most 👎 category</div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap gap-2 mb-6">
            <flux:select wire:model.live="severity_filter" class="min-w-40">
                <flux:select.option value="">All severities</flux:select.option>
                <flux:select.option value="must_fix">Must fix</flux:select.option>
                <flux:select.option value="should_fix">Should fix</flux:select.option>
                <flux:select.option value="consider">Consider</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="scope_filter" class="min-w-40">
                <flux:select.option value="">All scopes</flux:select.option>
                <flux:select.option value="full">Full</flux:select.option>
                <flux:select.option value="incremental">Incremental</flux:select.option>
            </flux:select>
            <flux:input wire:model.live.debounce.500ms="repo_filter" placeholder="Repo (e.g. geocodio/api)" />
            <flux:input wire:model.live.debounce.500ms="category_filter" placeholder="Category" />
            <flux:input wire:model.live.debounce.500ms="reviewer_filter" placeholder="Reviewer login" />
            <div class="flex items-center gap-2">
                <flux:switch wire:model.live="has_reactions_only" />
                <span class="text-sm text-yak-slate">With reactions only</span>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex gap-2 mb-4">
            <button
                wire:click="$set('active_tab', 'all')"
                class="px-4 py-2 rounded-[14px] text-sm font-medium transition-colors {{ $active_tab === 'all' ? 'bg-yak-orange text-white' : 'text-yak-blue hover:bg-yak-cream-dark' }}"
            >
                All comments
            </button>
            <button
                wire:click="$set('active_tab', 'by_reviewer')"
                class="px-4 py-2 rounded-[14px] text-sm font-medium transition-colors {{ $active_tab === 'by_reviewer' ? 'bg-yak-orange text-white' : 'text-yak-blue hover:bg-yak-cream-dark' }}"
            >
                By reviewer
            </button>
        </div>

        @if ($active_tab === 'all')
            <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-yak-cream-dark/60">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">PR</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">File</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">Severity</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">Reactions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($comments as $c)
                            <tr class="border-t border-yak-tan/20 hover:bg-yak-cream-dark/30">
                                <td class="px-4 py-3">
                                    <a href="{{ $c->review->pr_url ?? '#' }}" target="_blank" class="text-yak-orange hover:underline">
                                        {{ $c->review->repo }}#{{ $c->review->pr_number ?? '?' }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-yak-slate/80">{{ $c->file_path }}:{{ $c->line_number }}</td>
                                <td class="px-4 py-3">
                                    <flux:badge size="sm" :variant="$c->severity === 'must_fix' ? 'danger' : ($c->severity === 'should_fix' ? 'warning' : 'outline')">
                                        {{ $c->severity }}
                                    </flux:badge>
                                </td>
                                <td class="px-4 py-3 text-yak-slate">{{ $c->category }}</td>
                                <td class="px-4 py-3 text-yak-slate">
                                    @if ($c->thumbs_up > 0) <span class="mr-2">👍 {{ $c->thumbs_up }}</span> @endif
                                    @if ($c->thumbs_down > 0) <span>👎 {{ $c->thumbs_down }}</span> @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-yak-slate/60">No matching comments.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="p-4 border-t border-yak-tan/20">
                    {{ $comments->links() }}
                </div>
            </div>
        @else
            <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-yak-cream-dark/60">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">Reviewer</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">Reactions</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">👍</th>
                            <th class="px-4 py-3 text-left font-medium text-yak-slate">👎</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reviewerStats as $r)
                            <tr class="border-t border-yak-tan/20">
                                <td class="px-4 py-3">{{ $r->github_user_login }}</td>
                                <td class="px-4 py-3">{{ $r->total }}</td>
                                <td class="px-4 py-3">{{ $r->up }}</td>
                                <td class="px-4 py-3">{{ $r->down }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-yak-slate/60">No reactions yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
