<div wire:poll.{{ $this->pollInterval }}>
    @include('livewire.tasks.partials.header-band', [
        'task' => $task,
        'isActiveStatus' => $this->isActiveStatus(),
        'contextualAction' => $this->contextualAction,
        'outcomeButton' => $this->outcomeButton,
        'isResearchTask' => $this->isResearchTask(),
        'canReroute' => $this->canReroute,
        'rerouteOptions' => $this->rerouteOptions,
        'sourceUrl' => $this->sourceUrl,
        'nextSteps' => $this->nextSteps(),
        'detailedView' => $detailedView,
        'isAnsweredFix' => $this->isAnsweredFix,
    ])

    {{-- Branch deployment preview (renders only when the task's branch has an active deployment) --}}
    <livewire:tasks.preview-widget :task="$task" />

    {{-- Conversation thread --}}
    <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
        @foreach($this->thread as $i => $entry)
            <div
                wire:key="entry-{{ $i }}"
                @if($entry->kind === 'user' && $entry->run) id="turn-{{ $entry->run->id }}" @endif
            >
                @include('livewire.tasks.partials.thread-entry', [
                    'entry' => $entry,
                    'i' => $i,
                    'thread' => $this->thread,
                    'task' => $task,
                    'detailedView' => $detailedView,
                    'expandedTurns' => $expandedTurns,
                ])
            </div>
        @endforeach
    </div>

    @include('livewire.tasks.partials.composer', [
        'task' => $task,
        'composerText' => $composerText,
        'composerState' => $this->composerState,
        'head' => $this->task->conversation()->last() ?? $this->task,
    ])

    {{-- Video walkthrough (Reviewer Cut + Director's Cut) --}}
    @if($task->mode !== \App\Enums\TaskMode::Review)
        <livewire:tasks.video-walkthrough-card :task="$task" :key="'video-walkthrough-' . $task->id" />
    @endif

    {{-- Review-specific panels --}}
    @if($task->mode === \App\Enums\TaskMode::Review)
        <div class="mb-5 flex items-center gap-3 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:px-7">
            <flux:button wire:click="rerunReview" variant="outline" size="sm">
                Re-run review
            </flux:button>
            <span class="text-sm text-yak-slate/70">
                Re-runs this task against the current PR head. Prior review output on GitHub stays; Yak's stored findings for this task are replaced.
            </span>
        </div>
    @endif

    @if($task->mode === \App\Enums\TaskMode::Review && $this->prReview)
        @php $review = $this->prReview; @endphp
        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            <h2 class="mb-4 text-lg font-medium text-yak-slate">Review output</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-yak-slate/70">PR</dt>
                    <dd>
                        <a href="{{ $review->pr_url }}" target="_blank" class="font-medium text-yak-orange hover:text-yak-orange-warm">
                            {{ $review->repo }}#{{ $review->pr_number }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-yak-slate/70">Verdict</dt>
                    <dd class="text-yak-slate">{{ $review->verdict ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-yak-slate/70">Scope</dt>
                    <dd><flux:badge size="sm" :variant="$review->review_scope === 'full' ? 'outline' : 'primary'">{{ $review->review_scope }}</flux:badge></dd>
                </div>
                <div>
                    <dt class="text-yak-slate/70">Findings</dt>
                    <dd class="text-yak-slate">{{ $review->comments->count() }}</dd>
                </div>
                @if($review->dismissed_at)
                    <div>
                        <dt class="text-yak-slate/70">Dismissed</dt>
                        <dd class="text-yak-slate">{{ $review->dismissed_at->diffForHumans() }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            <h2 class="mb-4 text-lg font-medium text-yak-slate">Review preview</h2>
            <div class="prose prose-sm prose-yak max-w-none text-yak-slate prose-headings:text-yak-slate prose-a:text-yak-orange prose-strong:text-yak-slate">
                {!! $this->renderedReviewBody !!}
            </div>
        </div>

        @if($review->comments->isNotEmpty())
            <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
                <h2 class="mb-4 text-lg font-medium text-yak-slate">Findings</h2>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-yak-tan/30 text-left">
                            <th class="py-2 font-medium text-yak-slate">File</th>
                            <th class="py-2 font-medium text-yak-slate">Severity</th>
                            <th class="py-2 font-medium text-yak-slate">Category</th>
                            <th class="py-2 font-medium text-yak-slate">👍/👎</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($review->comments as $c)
                            <tr class="border-b border-yak-tan/20">
                                <td class="py-2 font-mono text-xs">{{ $c->file_path }}:{{ $c->line_number }}</td>
                                <td class="py-2">
                                    <flux:badge size="sm" :variant="$c->severity === 'must_fix' ? 'danger' : ($c->severity === 'should_fix' ? 'warning' : 'outline')">
                                        {{ $c->severity }}
                                    </flux:badge>
                                </td>
                                <td class="py-2 text-yak-slate">{{ $c->category }}</td>
                                <td class="py-2 text-yak-slate">{{ $c->thumbs_up }} / {{ $c->thumbs_down }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- Section 6: Screenshots --}}
    @if($this->screenshots->isNotEmpty() || $this->videos->isNotEmpty())
        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            <h2 class="mb-4 text-lg font-medium text-yak-slate">Media</h2>
            @if($this->screenshots->isNotEmpty())
                <div class="flex flex-wrap gap-5">
                    @foreach($this->screenshots as $screenshot)
                        @php $screenshotUrl = $screenshot->signedUrl(); @endphp
                        <div>
                            <a href="{{ $screenshotUrl }}" target="_blank" rel="noopener noreferrer" class="block">
                                <img src="{{ $screenshotUrl }}" alt="{{ $screenshot->filename }}" class="h-[200px] w-[300px] rounded-[14px] border border-[rgba(200,184,154,0.4)] object-cover" loading="lazy" />
                            </a>
                            <div class="mt-2 text-center text-xs">
                                <a href="{{ $screenshotUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">{{ $screenshot->filename }}</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($this->videos->isNotEmpty())
                <div class="mt-4 space-y-3">
                    @foreach($this->videos as $video)
                        @php $videoUrl = $video->signedUrl(); @endphp
                        <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                            <video controls class="w-full max-w-xl">
                                <source src="{{ $videoUrl }}" type="video/mp4">
                            </video>
                            <div class="bg-yak-cream-dark px-3 py-2 text-xs">
                                <a href="{{ $videoUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">{{ $video->filename }}</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Section 7: Debug Details (Collapsible) --}}
    <div class="mb-5 rounded-[14px] bg-yak-cream-dark p-5">
        <button wire:click="toggleDebug" class="flex w-full items-center justify-between">
            <h2 class="text-lg font-medium text-yak-slate">Debug Details</h2>
            <flux:icon.chevron-down class="!size-4.5 text-yak-blue transition-transform duration-200 {{ $showDebug ? 'rotate-180' : '' }}" />
        </button>
        @if($showDebug)
            <div class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3.5">
                @if($task->session_id)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Session ID</span>
                        <span class="text-sm text-yak-slate"><code class="font-mono text-[13px]">{{ $task->session_id }}</code></span>
                    </div>
                @endif
                @if($task->model_used)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Model</span>
                        <span class="text-sm text-yak-slate">{{ $task->model_used }}</span>
                    </div>
                @endif
                @if($task->num_turns)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Turns</span>
                        <span class="text-sm text-yak-slate">{{ $task->num_turns }}</span>
                    </div>
                @endif
                <div class="flex flex-col gap-0.5" title="List-price token cost reported by Claude Code. Covered by subscription — not billed.">
                    <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Claude Code cost (est.)</span>
                    <span class="text-sm text-yak-slate">${{ number_format((float) $task->cost_usd, 2) }}</span>
                </div>
                <div class="flex flex-col gap-0.5" title="Actual Anthropic API usage for this task (notification copy, routing).">
                    <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">API-billed spend</span>
                    <span class="text-sm text-yak-slate" data-testid="api-spend">${{ number_format($this->apiSpendUsd, 4) }}</span>
                </div>
                @if($task->branch_name && ! $this->isAnsweredFix)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Branch</span>
                        <span class="text-sm text-yak-slate"><code class="font-mono text-[13px]">{{ $task->branch_name }}</code></span>
                    </div>
                @endif
                @if($task->started_at)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Started</span>
                        <span class="text-sm text-yak-slate">{{ $task->started_at->format('M j, Y g:i:s A') }}</span>
                    </div>
                @endif
                @if($task->completed_at)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Completed</span>
                        <span class="text-sm text-yak-slate">{{ $task->completed_at->format('M j, Y g:i:s A') }}</span>
                    </div>
                @endif
                @if($task->attempts > 0)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Attempts</span>
                        <span class="text-sm text-yak-slate">{{ $task->attempts }}</span>
                    </div>
                @endif
            </div>
            @if($task->error_log)
                <div class="mt-4">
                    <span class="text-xs font-medium uppercase tracking-wider text-yak-blue">Error Log</span>
                    <pre class="mt-1 max-h-60 overflow-auto rounded-xl bg-[#2b3640] p-4 font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $task->error_log }}</pre>
                </div>
            @endif
        @endif
    </div>


    {{-- Section 8: Activity (Unified log with milestone highlighting) --}}
    @if($this->hasLogs)
        <div
            class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]"
            x-data="activityFollow()"
        >

            {{-- Milestone progress bar --}}
            <div class="mb-5 flex items-center gap-0" data-testid="milestone-stepper">
                @foreach($this->milestoneSteps as $stepIndex => $step)
                    <div class="flex items-center {{ $stepIndex < count($this->milestoneSteps) - 1 ? 'flex-1' : '' }}">
                        <flux:tooltip :content="$step['tooltip']">
                            <button type="button" class="flex flex-col items-center gap-1 cursor-help" data-testid="milestone-step-{{ $stepIndex }}">
                                <div class="flex size-6 items-center justify-center rounded-full text-[10px] font-semibold {{ $step['completed'] ? ($step['active'] ? 'bg-yak-green text-white' : 'bg-[rgba(122,140,94,0.25)] text-yak-green') : 'bg-[rgba(200,184,154,0.25)] text-yak-tan' }}">
                                    @if($step['completed'])
                                        <flux:icon.check class="!size-3.5" />
                                    @else
                                        {{ $stepIndex + 1 }}
                                    @endif
                                </div>
                                <span class="whitespace-nowrap text-[10px] font-medium {{ $step['completed'] ? ($step['active'] ? 'text-yak-green' : 'text-yak-blue') : 'text-yak-tan' }}">{{ $step['label'] }}</span>
                            </button>
                        </flux:tooltip>
                        @if($stepIndex < count($this->milestoneSteps) - 1)
                            <div class="mx-1 mb-4 h-0.5 flex-1 {{ $step['completed'] ? 'bg-[rgba(122,140,94,0.3)]' : 'bg-[rgba(200,184,154,0.2)]' }}"></div>
                        @endif
                    </div>
                @endforeach
                <a
                    href="{{ \App\Support\Docs::url('architecture.core-loop') }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ml-3 mb-4 shrink-0 text-yak-tan hover:text-yak-slate transition-colors"
                    title="Learn how Yak progresses through a task"
                    aria-label="Learn about the task lifecycle"
                    data-testid="milestone-docs-link"
                >
                    <flux:icon.question-mark-circle class="!size-4" />
                </a>
            </div>

            <div class="mb-5 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                <h2 class="text-lg font-medium text-yak-slate">Activity</h2>
                <span class="text-xs text-yak-blue">
                    {{ $this->logs->count() }} entries
                    @if($task->num_turns) &middot; {{ $task->num_turns }} turns @endif
                    @if($task->duration_ms) &middot; {{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }} @endif
                    @if($task->cost_usd) &middot; ${{ number_format((float) $task->cost_usd, 2) }} @endif
                </span>
            </div>

            {{-- Attempt selector (only shown when the task was retried) --}}
            @if(count($this->availableAttempts) > 0)
                <div class="mb-3 flex flex-wrap items-center gap-2" data-testid="attempt-selector">
                    <span class="text-xs font-medium text-yak-tan">Attempt</span>
                    @foreach($this->availableAttempts as $attempt)
                        <button
                            wire:click="selectAttempt({{ $attempt }})"
                            class="rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors {{ $visibleAttempt === $attempt ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.5)]' }}"
                            data-testid="attempt-{{ $attempt }}"
                        >
                            #{{ $attempt }}@if($attempt === (int) $task->attempts) <span class="text-yak-tan">latest</span>@endif
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Filter buttons --}}
            <div class="mb-4 flex gap-2" data-testid="log-filters">
                @foreach(['all' => 'All', 'actions' => 'Actions', 'milestones' => 'Milestones'] as $filterKey => $filterLabel)
                    <button
                        wire:click="setFilter('{{ $filterKey }}')"
                        class="rounded-lg border px-3 py-1 text-xs font-medium transition-colors {{ $logFilter === $filterKey ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.5)]' }}"
                        data-testid="filter-{{ $filterKey }}"
                    >
                        {{ $filterLabel }}
                    </button>
                @endforeach
            </div>

            <div class="relative">
                <div
                    x-ref="logList"
                    wire:poll.{{ $this->pollInterval }}="$refresh"
                    class="max-h-[600px] overflow-y-auto"
                    @scroll.passive="onScroll()"
                >
                @foreach($this->groupedLogs as $entry)
                    @if($entry['type'] === 'group')
                        {{-- Collapsed group of consecutive assistant entries --}}
                        @php
                            $groupIndex = $entry['groupIndex'];
                            $isGroupExpanded = $expandedGroups[$groupIndex] ?? false;
                            $lastLog = $entry['last'];
                        @endphp
                        <div
                            class="mb-2 overflow-hidden rounded-xl border border-[rgba(200,184,154,0.3)] bg-white"
                            wire:key="group-{{ $groupIndex }}"
                            data-testid="log-entry"
                        >
                            <button wire:click="toggleGroup({{ $groupIndex }})" class="flex w-full flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)] sm:flex-nowrap sm:gap-x-3 sm:gap-y-0">
                                <flux:icon.chevron-right class="!size-3.5 shrink-0 text-yak-tan transition-transform duration-150 {{ $isGroupExpanded ? 'rotate-90' : '' }}" />
                                <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.1)] px-2 py-0.5 font-mono text-[11px] font-semibold text-yak-blue" data-testid="thinking-steps-badge">{{ $entry['count'] }} thinking steps</span>
                                <span class="order-last min-w-0 basis-full truncate text-[13px] italic text-yak-blue sm:order-none sm:basis-0 sm:flex-1">{{ $lastLog->message }}</span>
                                <span class="ml-auto shrink-0 font-mono text-[11px] text-yak-tan sm:ml-0">
                                    @if($this->isActiveStatus())
                                        {{ $lastLog->created_at->diffForHumans() }}
                                    @else
                                        {{ $lastLog->created_at->format('g:i:s A') }}
                                    @endif
                                </span>
                            </button>
                            @if($isGroupExpanded)
                                <div class="border-t border-[rgba(200,184,154,0.25)] bg-[rgba(245,240,232,0.3)]">
                                    @foreach($entry['logs'] as $gIdx => $groupLog)
                                        <div class="border-b border-[rgba(200,184,154,0.15)] px-4 py-2 last:border-b-0">
                                            <span class="text-[13px] italic text-yak-blue">{{ $groupLog->message }}</span>
                                            <span class="ml-2 font-mono text-[10px] text-yak-tan">
                                                @if($this->isActiveStatus())
                                                    {{ $groupLog->created_at->diffForHumans() }}
                                                @else
                                                    {{ $groupLog->created_at->format('g:i:s A') }}
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- Single log entry --}}
                        @php
                            $log = $entry['log'];
                            $index = $entry['index'];
                            $logType = $log->metadata['type'] ?? null;
                            $isToolUse = $logType === 'tool_use';
                            $isAssistant = $logType === 'assistant';
                            $isPrompt = $logType === 'prompt';
                            $hasOutput = $isToolUse && isset($log->metadata['output']);
                            $hasToolInput = $isToolUse && ! empty($log->metadata['input']);
                            $isError = $log->metadata['is_error'] ?? false;
                            $hasExpandableContent = $hasOutput || $hasToolInput || $isPrompt || $log->metadata;
                            $isMilestone = \App\Livewire\Tasks\TaskDetail::isMilestone($log);
                            // Auto-expand errors, auto-collapse assistant
                            $defaultExpanded = $isError && $hasOutput;
                            $isExpanded = $expandedLogs[$index] ?? $defaultExpanded;
                        @endphp
                        <div
                            class="mb-2 overflow-hidden rounded-xl border border-[rgba(200,184,154,0.3)] bg-white"
                            wire:key="log-{{ $log->id }}"
                            data-testid="{{ $isMilestone ? 'milestone-log' : 'log-entry' }}"
                        >
                            <button wire:click="toggleLog({{ $index }})" class="flex w-full flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)] sm:flex-nowrap sm:gap-x-3 sm:gap-y-0 {{ $isMilestone ? 'bg-[rgba(245,240,232,0.3)]' : '' }}">
                                @if($hasExpandableContent && !$isMilestone)
                                    <flux:icon.chevron-right class="!size-3.5 shrink-0 text-yak-tan transition-transform duration-150 {{ $isExpanded ? 'rotate-90' : '' }}" />
                                @else
                                    <span class="size-3.5 shrink-0"></span>
                                @endif
                                <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.1)] px-2 py-0.5 font-mono text-[11px] font-semibold text-yak-blue">{{ $index + 1 }}</span>
                                @if($isToolUse)
                                    <span class="shrink-0 rounded-md px-2 py-0.5 font-mono text-[11px] font-medium {{ $isError ? 'bg-[rgba(184,84,80,0.15)] text-yak-danger' : 'bg-[rgba(122,140,94,0.15)] text-yak-green' }}">
                                        {{ $log->metadata['tool'] ?? 'tool' }}
                                    </span>
                                @elseif($isPrompt)
                                    <span class="shrink-0 rounded-md bg-[rgba(212,145,94,0.15)] px-2 py-0.5 font-mono text-[11px] font-medium text-yak-orange-warm">
                                        prompt
                                    </span>
                                @elseif(!$isAssistant)
                                    <span class="shrink-0 rounded-md px-2 py-0.5 font-mono text-[11px] font-medium {{ $log->level === 'error' ? 'bg-[rgba(184,84,80,0.15)] text-yak-danger' : ($log->level === 'warning' ? 'bg-[rgba(212,145,94,0.15)] text-yak-orange-warm' : 'bg-[rgba(143,179,196,0.15)] text-[#5a8da5]') }}">
                                        {{ $log->level }}
                                    </span>
                                @endif
                                <span class="order-last min-w-0 basis-full truncate text-[13px] sm:order-none sm:basis-0 sm:flex-1 {{ $isAssistant ? 'italic text-yak-blue' : 'text-yak-slate' }} {{ $isMilestone ? 'font-semibold' : '' }}">{{ $log->message }}</span>
                                @if($hasOutput)
                                    <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.08)] px-1.5 py-0.5 font-mono text-[10px] text-yak-blue">
                                        {{ $log->metadata['output_lines'] ?? '?' }} lines
                                    </span>
                                @endif
                                <span class="ml-auto shrink-0 font-mono text-[11px] text-yak-tan sm:ml-0">
                                    @if($this->isActiveStatus())
                                        {{ $log->created_at->diffForHumans() }}
                                    @else
                                        {{ $log->created_at->format('g:i:s A') }}
                                    @endif
                                </span>
                            </button>
                            @if($isExpanded)
                                <div class="border-t border-[rgba(200,184,154,0.25)] bg-[#2b3640] p-4 space-y-3">
                                    @if($isPrompt)
                                        <div>
                                            <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-orange-warm">User prompt</div>
                                            <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $log->metadata['prompt'] ?? '' }}</pre>
                                        </div>
                                        <div>
                                            <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-orange-warm">System prompt</div>
                                            <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $log->metadata['system_prompt'] ?? '' }}</pre>
                                        </div>
                                        <div class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-[11px] text-[#a8a8a8]">
                                            <div><span class="text-[#8a8a8a]">model:</span> {{ $log->metadata['model'] ?? '-' }}</div>
                                            <div><span class="text-[#8a8a8a]">max_turns:</span> {{ $log->metadata['max_turns'] ?? '-' }}</div>
                                            <div><span class="text-[#8a8a8a]">max_budget_usd:</span> {{ $log->metadata['max_budget_usd'] ?? '-' }}</div>
                                            <div><span class="text-[#8a8a8a]">resume_session_id:</span> {{ $log->metadata['resume_session_id'] ?? '-' }}</div>
                                        </div>
                                    @else
                                        @if($hasToolInput)
                                            <div>
                                                <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-green">
                                                    {{ ($log->metadata['tool'] ?? 'tool') === 'Bash' ? 'Command' : 'Input' }}
                                                </div>
                                                @if(($log->metadata['tool'] ?? null) === 'Bash' && isset($log->metadata['input']['command']))
                                                    <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#f5e9c9]">{{ $log->metadata['input']['command'] }}</pre>
                                                    @if(! empty($log->metadata['input']['description']))
                                                        <div class="mt-1 font-mono text-[11px] italic text-[#8a8a8a]"># {{ $log->metadata['input']['description'] }}</div>
                                                    @endif
                                                @else
                                                    <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ json_encode($log->metadata['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                @endif
                                            </div>
                                        @endif
                                        @if($hasOutput)
                                            <div>
                                                @if($hasToolInput)
                                                    <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-blue">Output</div>
                                                @endif
                                                <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $log->metadata['output'] }}</pre>
                                            </div>
                                        @elseif(! $hasToolInput)
                                            <div class="max-h-80 overflow-auto whitespace-pre-wrap break-words text-sm leading-relaxed text-[#d4d4d4]">{{ $log->message }}</div>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
                </div>

                <button
                    type="button"
                    x-show="!following"
                    x-cloak
                    x-transition.opacity
                    @click="jumpToLatest()"
                    class="absolute bottom-3 right-3 inline-flex items-center gap-1.5 rounded-full bg-yak-orange px-3 py-1.5 text-xs font-medium text-white shadow-lg transition-colors hover:bg-yak-orange-warm"
                    data-testid="jump-to-latest"
                >
                    <flux:icon.arrow-down class="!size-3.5" />
                    <span>Jump to latest</span>
                </button>
            </div>
        </div>
    @endif
</div>
