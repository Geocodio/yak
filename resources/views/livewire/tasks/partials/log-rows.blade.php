{{--
    The activity log entries themselves: collapsed groups of consecutive
    thinking steps, and single rows that open the transcript overlay.
    Shared by the sidebar card and the overlay's left rail.

    Expects: $keyPrefix (string, distinguishes wire:keys when rendered
    more than once per page), $expandedGroups (array<int, bool>),
    $logSearch (string).
--}}
        @if(count($this->groupedLogs) === 0 && trim($logSearch) !== '')
            <p class="px-3 py-6 text-center text-xs text-yak-tan" data-testid="log-search-empty">
                No entries match &ldquo;{{ $logSearch }}&rdquo;.
            </p>
        @endif
        @foreach($this->groupedLogs as $entry)
            @if($entry['type'] === 'group')
                {{-- Collapsed group of consecutive assistant entries --}}
                @php
                    $groupIndex = $entry['groupIndex'];
                    $isGroupExpanded = $expandedGroups[$groupIndex] ?? false;
                    $lastLog = $entry['last'];
                @endphp
                <div
                    class="mb-1.5 overflow-hidden rounded-lg border border-[rgba(200,184,154,0.3)] bg-white"
                    wire:key="{{ $keyPrefix }}group-{{ $groupIndex }}"
                    data-testid="log-entry"
                >
                    <button wire:click="toggleGroup({{ $groupIndex }})" class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)]">
                        <flux:icon.chevron-right class="!size-3 shrink-0 text-yak-tan transition-transform duration-150 {{ $isGroupExpanded ? 'rotate-90' : '' }}" />
                        <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.1)] px-1.5 py-0.5 font-mono text-[10px] font-semibold text-yak-blue" data-testid="thinking-steps-badge">{{ $entry['count'] }} thinking steps</span>
                        <span class="min-w-0 flex-1 truncate text-xs italic text-yak-blue">{{ \App\Support\Markdown::toPlainText($lastLog->message) }}</span>
                    </button>
                    @if($isGroupExpanded)
                        <div class="border-t border-[rgba(200,184,154,0.25)] bg-[rgba(245,240,232,0.3)]">
                            @foreach($entry['logs'] as $groupLog)
                                <div class="border-b border-[rgba(200,184,154,0.15)] px-3 py-1.5 last:border-b-0">
                                    <span class="text-xs italic text-yak-blue">{{ \App\Support\Markdown::toPlainText($groupLog->message) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                {{-- Single log entry: click opens the transcript overlay --}}
                @php
                    $log = $entry['log'];
                    $logType = $log->metadata['type'] ?? null;
                    $isToolUse = $logType === 'tool_use';
                    $isAssistant = $logType === 'assistant';
                    $isPrompt = $logType === 'prompt';
                    $hasOutput = $isToolUse && isset($log->metadata['output']);
                    $hasToolInput = $isToolUse && ! empty($log->metadata['input']);
                    $isError = $log->metadata['is_error'] ?? false;
                    $hasExpandableContent = $hasOutput || $hasToolInput || $isPrompt || $log->metadata;
                    $isMilestone = \App\Livewire\Tasks\TaskDetail::isMilestone($log);
                @endphp
                @php $isOpenInDrawer = $this->transcriptLogId === $log->id; @endphp
                <div
                    class="mb-1.5 overflow-hidden rounded-lg border bg-white {{ $isOpenInDrawer ? 'border-yak-orange ring-1 ring-yak-orange/40' : 'border-[rgba(200,184,154,0.3)]' }}"
                    wire:key="{{ $keyPrefix }}log-{{ $log->id }}"
                    data-testid="{{ $isOpenInDrawer ? 'log-entry-open' : ($isMilestone ? 'milestone-log' : 'log-entry') }}"
                >
                    <button
                        @if($hasExpandableContent && !$isMilestone) wire:click="openTranscript({{ $log->id }})" @endif
                        class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)] {{ $isMilestone ? 'bg-[rgba(245,240,232,0.3)]' : '' }}"
                    >
                        @if($isToolUse)
                            <span class="shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-medium {{ $isError ? 'bg-[rgba(184,84,80,0.15)] text-yak-danger' : 'bg-[rgba(122,140,94,0.15)] text-yak-green' }}">
                                {{ $log->metadata['tool'] ?? 'tool' }}
                            </span>
                        @elseif($isPrompt)
                            <span class="shrink-0 rounded-md bg-[rgba(212,145,94,0.15)] px-1.5 py-0.5 font-mono text-[10px] font-medium text-yak-orange-warm">
                                prompt
                            </span>
                        @elseif(!$isAssistant)
                            <span class="shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-medium {{ $log->level === 'error' ? 'bg-[rgba(184,84,80,0.15)] text-yak-danger' : ($log->level === 'warning' ? 'bg-[rgba(212,145,94,0.15)] text-yak-orange-warm' : 'bg-[rgba(143,179,196,0.15)] text-[#5a8da5]') }}">
                                {{ $log->level }}
                            </span>
                        @endif
                        <span class="min-w-0 flex-1 truncate text-xs {{ $isAssistant ? 'italic text-yak-blue' : 'text-yak-slate' }} {{ $isMilestone ? 'font-semibold' : '' }}">{{ \App\Support\Markdown::toPlainText($log->message) }}</span>
                        <span class="ml-auto shrink-0 font-mono text-[10px] text-yak-tan">
                            @if($this->isActiveStatus())
                                {{ $log->created_at->diffForHumans() }}
                            @else
                                {{ $log->created_at->format('g:i:s A') }}
                            @endif
                        </span>
                    </button>
                </div>
            @endif
        @endforeach
