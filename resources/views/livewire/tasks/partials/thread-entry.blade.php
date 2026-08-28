{{--
    One entry in the conversation thread. Branches on $entry->kind:
    'user' | 'clarification' | 'yak' | 'system'.

    Expects: $entry (App\DataTransferObjects\ThreadEntry), $i (int, index in
    the thread), $thread (Collection<int, ThreadEntry>), $task (App\Models\YakTask,
    root task), $detailedView (bool), $expandedTurns (array<int, bool>),
    $clarificationTtl (?string, from TaskDetail::clarificationTtl()),
    $review (?App\Models\PrReview, from TaskDetail::prReview()),
    $mediaByRun (Collection<int, Collection<int, App\Models\Artifact>>,
    from TaskDetail::mediaByRun(), keyed by run id).
--}}
@if($entry->kind === 'user')
    @php
        $isReviewContextTurn = $task->mode === \App\Enums\TaskMode::Review && $i === 0;
    @endphp
    @if($isReviewContextTurn)
        @php
            $context = json_decode((string) ($entry->run->context ?? ''), true) ?: [];
            $prNumber = $context['pr_number'] ?? null;
            $author = $context['author'] ?? null;
            $prTitle = $context['title'] ?? null;
            $prBody = $context['body'] ?? null;
        @endphp
        <div class="mb-4 flex gap-3">
            <div class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-yak-slate text-white">
                <flux:icon.code-bracket class="!size-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-xs text-yak-blue">
                    @if($prNumber)PR #{{ $prNumber }}@else Pull request @endif
                    @if($author) opened by {{ $author }} @endif
                    <span class="ml-1 font-mono">{{ $entry->timestamp->format('g:i A') }}</span>
                </div>
                @if($prTitle)
                    <div class="mt-1 font-medium text-yak-slate">{{ $prTitle }}</div>
                @endif
                {{-- The PR body is the one thing on a review task that is
                     always truncated, so it is what "Detailed" un-truncates. --}}
                <x-markdown
                    :text="$prBody"
                    :compact="! $detailedView"
                    class="mt-1 text-sm !text-yak-blue {{ $detailedView ? '' : 'line-clamp-3' }}"
                    data-testid="pr-body"
                />
            </div>
        </div>
    @else
        @php
            $authorName = $entry->authorName;
            $initial = strtoupper(substr($authorName ?? optional(auth()->user())->name ?? 'You', 0, 1));
            $showSummary = $entry->summary && ! $detailedView && ! ($expandedTurns[$i] ?? false);
        @endphp
        <div class="mb-4 flex gap-3">
            <div
                class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-yak-blue text-sm font-medium text-white"
                @if($authorName) title="{{ $authorName }}" @endif
            >
                {{ $initial }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-xs text-yak-blue">
                    @if($authorName)
                        <span class="font-medium text-yak-slate">{{ $authorName }}</span>
                        &middot;
                    @endif
                    via {{ $entry->source ? ucfirst($entry->source) : 'dashboard' }}
                    &middot;
                    <span class="font-mono">{{ $entry->timestamp->format('g:i A') }}</span>
                </div>
                <div class="mt-1 text-sm text-yak-slate">
                    @if($showSummary)
                        <p>{{ $entry->summary }}</p>
                        <button
                            type="button"
                            wire:click="toggleTurn({{ $i }})"
                            class="mt-1 text-xs font-medium text-yak-blue hover:text-yak-slate"
                        >
                            full request &middot; {{ strlen($entry->text) }} chars &#9656;
                        </button>
                    @else
                        <x-markdown :text="$entry->text" />
                    @endif
                </div>
            </div>
        </div>
    @endif
@elseif($entry->kind === 'clarification')
    @php
        $nextUser = $thread->slice($i + 1)->first(fn ($e) => $e->kind === 'user');
        $isAnswered = $nextUser !== null;
        $answerText = $isAnswered ? $nextUser->text : null;
    @endphp
    <div class="mb-4 flex gap-3">
        <img src="{{ asset('mascot-avatar.png') }}" alt="Yak" title="Yak" class="size-[34px] shrink-0 rounded-full border border-[rgba(200,184,154,0.5)]" />
        <div class="min-w-0 flex-1">
            @php
                $ttl = ($entry->run && $entry->run->is($task) && $task->status === \App\Enums\TaskStatus::AwaitingClarification)
                    ? ($clarificationTtl ?? null)
                    : null;
            @endphp
            <div class="text-xs text-yak-blue">
                <span class="font-mono">{{ $entry->timestamp->format('g:i A') }}</span>
                @if($ttl)
                    <span>&middot; {{ $ttl === 'Expired' ? 'Expired' : 'expires ' . $ttl }}</span>
                @endif
            </div>
            <p class="mt-1 text-sm text-yak-slate">{{ $entry->text }}</p>
            @if($entry->options)
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($entry->options as $option)
                        @php $isChosen = $isAnswered && $answerText === $option; @endphp
                        <button
                            type="button"
                            @unless($isAnswered) wire:click="prefillOption('{{ $option }}')" @endunless
                            @if($isAnswered) disabled @endif
                            class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors {{ $isChosen ? 'border-yak-orange bg-[rgba(196,116,74,0.12)] text-yak-orange' : ($isAnswered ? 'border-[rgba(200,184,154,0.4)] text-yak-tan' : 'border-[rgba(200,184,154,0.5)] text-yak-slate hover:border-yak-orange hover:text-yak-orange') }}"
                        >
                            @if($isChosen)
                                <flux:icon.check class="!size-3" />
                            @endif
                            {{ $option }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@elseif($entry->kind === 'yak')
    @php
        $steps = $entry->runStats['steps'] ?? 0;
        $duration = \App\Livewire\Tasks\TaskList::formatDuration($entry->runStats['duration_ms'] ?? null);
        $lastLogMessage = $entry->isLive ? optional($entry->run?->logs()->latest('created_at')->first())->message : null;
        // D12: in condensed view, results superseded by a later run collapse
        // to a clamp so the thread foregrounds the newest answer.
        $lastYakIndex = $thread->filter(fn ($e) => $e->kind === 'yak')->keys()->last();
        $isSuperseded = ! $detailedView
            && ! $entry->isLive
            && $entry->error === null
            && $i !== $lastYakIndex
            && ! ($expandedTurns[$i] ?? false);
    @endphp
    <div class="mb-4 flex gap-3">
        <img src="{{ asset('mascot-avatar.png') }}" alt="Yak" title="Yak" class="size-[34px] shrink-0 rounded-full border border-[rgba(200,184,154,0.5)]" />
        <div class="min-w-0 flex-1">
            <button
                type="button"
                wire:click="focusRun({{ $entry->run?->id }})"
                class="text-xs text-yak-blue hover:text-yak-slate"
                data-testid="work-summary-row"
            >
                Worked for {{ $duration }} &middot; {{ $steps }} {{ Str::plural('step', $steps) }}
            </button>

            @if($entry->isLive)
                <div class="mt-2 rounded-xl border border-yak-orange/25 bg-yak-orange/5 px-3 py-2 text-sm text-yak-orange-warm" data-testid="live-activity">
                    {{ $lastLogMessage ? \App\Support\Markdown::toPlainText($lastLogMessage) : 'Working…' }}
                </div>
            @endif

            @if($entry->error)
                <div class="mt-2 rounded-xl border border-[rgba(184,84,80,0.2)] bg-[rgba(184,84,80,0.06)] px-4 py-3">
                    <span class="text-xs font-medium uppercase tracking-wider text-yak-danger">Error</span>
                    <p class="mt-1 text-sm leading-relaxed text-yak-slate">{{ Str::limit($entry->error, 400) }}</p>
                </div>
            @endif

            @if($entry->text !== '')
                @if($isSuperseded)
                    <x-markdown :text="$entry->text" compact class="mt-2 line-clamp-3" data-testid="superseded-result" />
                    <button
                        type="button"
                        wire:click="toggleTurn({{ $i }})"
                        class="mt-1 text-xs font-medium text-yak-orange hover:text-yak-orange-warm"
                        data-testid="show-superseded-result"
                    >
                        Show full result &#9656;
                    </button>
                @else
                    <x-markdown :text="$entry->text" class="mt-2" />
                @endif
            @endif

            @if($task->mode === \App\Enums\TaskMode::Review && isset($review) && $review && $entry->run && $review->yak_task_id === $entry->run->id)
                @include('livewire.tasks.partials.findings-block', ['review' => $review])
            @endif

            @if($entry->run)
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    @if($entry->run->pr_url)
                        @php
                            $prState = $entry->run->pr_merged_at ? 'merged' : ($entry->run->pr_closed_at ? 'closed' : 'open');
                            // A follow-up run pushes to a PR that already
                            // exists, so say so — same distinction
                            // ProcessCIResultJob draws when it notifies.
                            $prLabel = $entry->run->parent_task_id !== null ? 'Pull request updated' : 'Pull Request';
                        @endphp
                        <a
                            href="{{ $entry->run->pr_url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-yak-orange hover:text-yak-orange-warm"
                            data-testid="pr-link"
                        >
                            <flux:icon.arrow-top-right-on-square class="!size-3" />
                            {{ $prLabel }}
                            <span class="text-yak-tan">&middot; {{ $prState }}</span>
                        </a>
                    @endif
                    @if($entry->run->mode === \App\Enums\TaskMode::Research)
                        @php
                            $researchArtifact = $entry->run->is($task)
                                ? $this->researchArtifact
                                : $entry->run->artifacts()->where('type', 'research')->first();
                        @endphp
                        @if($researchArtifact)
                            <span class="text-xs text-yak-blue">
                                <span class="font-medium">Research Findings:</span>
                                <a
                                    href="{{ route('artifacts.viewer', ['task' => $entry->run->id, 'filename' => $researchArtifact->filename]) }}"
                                    class="font-medium text-yak-orange hover:text-yak-orange-warm"
                                    data-testid="research-link"
                                >
                                    View research artifact
                                </a>
                            </span>
                        @endif
                    @endif
                </div>
            @endif

            @php $media = $entry->run ? ($mediaByRun[$entry->run->id] ?? collect()) : collect(); @endphp
            @if($media->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-3" data-testid="turn-media">
                    @foreach($media as $artifact)
                        <button
                            type="button"
                            wire:click="openMediaLightbox({{ $artifact->id }})"
                            class="block w-[150px] shrink-0 overflow-hidden rounded-[10px] border border-[rgba(200,184,154,0.45)] text-left transition-shadow hover:shadow-[0_4px_10px_rgba(61,79,95,0.08)]"
                            data-testid="media-thumb-{{ $artifact->id }}"
                        >
                            @if($artifact->type === 'video')
                                <video muted preload="metadata" class="h-[100px] w-full bg-yak-cream-dark object-cover" src="{{ $artifact->signedUrl() }}"></video>
                            @else
                                <img src="{{ $artifact->signedUrl() }}" alt="{{ $artifact->filename }}" loading="lazy" class="h-[100px] w-full object-cover" />
                            @endif
                            <div class="truncate bg-yak-cream-dark px-2 py-1 text-[11px] text-yak-blue">{{ $artifact->filename }}</div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@elseif($entry->kind === 'system')
    <div class="my-3 flex items-center justify-center gap-2 text-xs text-yak-tan">
        <span>&#8635; {{ $entry->text }}</span>
    </div>
@endif
