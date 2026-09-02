{{--
    Header band: breadcrumb, status, title, actions, meta line.

    Expects (in addition to $task): $isActiveStatus, $contextualAction,
    $outcomeButton, $isResearchTask, $canReroute, $rerouteOptions,
    $sourceUrl, $nextSteps, $detailedView, $isAnsweredFix,
    $deployment (?App\Models\BranchDeployment, from TaskDetail::deployment()).
--}}
@php
    $headlineFirstLine = \Illuminate\Support\Str::before($task->description, "\n");
    $headline = ($task->description_summary && strlen($task->description_summary) < strlen($headlineFirstLine))
        ? $task->description_summary
        : $headlineFirstLine;
@endphp
<div class="mb-5 rounded-[28px] border border-white/60 bg-white/75 p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)] backdrop-blur-[40px] backdrop-saturate-[1.4]">
    <div class="mb-6 text-sm">
        <a href="{{ route('tasks') }}" class="font-medium text-yak-orange hover:text-yak-orange-warm">Tasks</a>
        <span class="text-yak-blue"> / </span>
        <span class="text-yak-blue">{{ $task->external_id ?? '#'.$task->id }}</span>
    </div>

    <div class="flex flex-col gap-3">
        <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3.5">
            <div class="flex flex-wrap items-center gap-3.5">
                <span class="inline-flex items-center rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Tasks\Support\TaskStyling::statusBadgeClasses($task->status) }}">
                    @if($isActiveStatus)
                        <span class="mr-1.5 inline-block size-1.5 animate-pulse rounded-full bg-current"></span>
                    @endif
                    {{ str_replace('_', ' ', $task->status->value) }}
                </span>
                <span class="text-xs text-yak-blue">#{{ $task->id }}</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="detailsDrawerOpen = true"
                    class="relative inline-flex items-center gap-1.5 rounded-xl border border-[rgba(200,184,154,0.4)] px-3 py-2 text-sm font-medium text-yak-slate transition-colors hover:bg-[rgba(245,240,232,0.5)] lg:hidden"
                    data-testid="details-drawer-trigger"
                >
                    <flux:icon.bars-3 class="!size-4" />
                    <span>Details</span>
                    @if($isActiveStatus)
                        <span class="absolute -right-0.5 -top-0.5 size-2 rounded-full bg-yak-orange" aria-hidden="true"></span>
                    @endif
                </button>

                @if($contextualAction === 'retry')
                    <flux:button variant="filled" size="sm" icon="arrow-path" wire:click="retry" wire:confirm="Re-queue this task?">Retry</flux:button>
                @elseif($contextualAction === 'cancel')
                    <flux:button variant="ghost" size="sm" icon="x-circle" wire:click="cancel" wire:confirm="Cancel this task? The sandbox will be destroyed and the agent will stop." data-testid="cancel-button">Cancel</flux:button>
                @elseif($contextualAction === 'rerun_review')
                    <flux:button variant="filled" size="sm" icon="arrow-path" wire:click="rerunReview">Re-run review</flux:button>
                @endif

                @if($deployment)
                    <a
                        href="https://{{ $deployment->hostname }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-xl border border-[rgba(200,184,154,0.4)] px-4 py-2.5 text-sm font-medium text-yak-slate transition-colors hover:bg-[rgba(245,240,232,0.5)]"
                        data-testid="preview-button"
                    >
                        <flux:icon.globe-alt class="!size-4" />
                        <span>Preview</span>
                    </a>
                @endif

                @if($outcomeButton)
                    <a
                        href="{{ $outcomeButton['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-xl bg-yak-orange px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-yak-orange-warm transition-colors"
                        data-testid="{{ $isResearchTask ? 'research-report-button' : 'outcome-button' }}"
                    >
                        <flux:icon.document-text class="!size-4" />
                        <span>{{ $outcomeButton['label'] }}</span>
                    </a>
                @endif

                @if($canReroute && $rerouteOptions->isNotEmpty())
                    <flux:dropdown>
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" data-testid="reroute-trigger" />
                        <flux:menu data-testid="reroute-menu">
                            @foreach($rerouteOptions as $option)
                                <flux:menu.item
                                    wire:click="rerouteRepo('{{ $option->slug }}')"
                                    wire:confirm="Move this task to {{ $option->slug }}? The current sandbox (if any) will be destroyed and the task will restart there."
                                    data-testid="reroute-to-{{ $option->slug }}"
                                >
                                    Move to {{ $option->slug }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        <h1 class="text-lg font-medium leading-snug text-yak-slate">{{ $headline }}</h1>

        @if($task->status === \App\Enums\TaskStatus::Failed && $task->error_log)
            <div class="mt-2 rounded-xl border border-[rgba(184,84,80,0.2)] bg-[rgba(184,84,80,0.06)] px-4 py-3">
                <span class="text-xs font-medium uppercase tracking-wider text-yak-danger">Error</span>
                <p class="mt-1 text-sm leading-relaxed text-yak-slate">{{ $task->error_log }}</p>
            </div>
        @endif

        @if($nextSteps)
            <p class="mt-1 text-sm italic text-yak-blue" data-testid="next-steps">{{ $nextSteps }}</p>
        @endif

        <div class="mt-1 flex flex-wrap items-center gap-4">
            <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                <flux:icon.wrench-screwdriver class="!size-3.5" />
                <span class="font-medium">Mode:</span>
                <span class="text-yak-slate">{{ ucfirst($task->mode->value) }}</span>
            </span>
            @if($task->repo)
                <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                    <flux:icon.code-bracket class="!size-3.5" />
                    <span class="font-medium">Repo:</span>
                    @if($task->repository)
                        <a href="{{ route('repos.edit', $task->repository) }}" wire:navigate class="font-medium text-yak-orange hover:text-yak-orange-warm">{{ $task->repo }}</a>
                    @else
                        <span class="text-yak-slate">{{ $task->repo }}</span>
                    @endif
                </span>
            @endif
            @if($task->source)
                <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                    @if($task->source === 'slack')
                        <flux:icon.chat-bubble-left class="!size-3.5" />
                    @elseif($task->source === 'sentry')
                        <flux:icon.shield-exclamation class="!size-3.5" />
                    @elseif($task->source === 'linear')
                        <flux:icon.bolt class="!size-3.5" />
                    @else
                        <flux:icon.command-line class="!size-3.5" />
                    @endif
                    <span class="font-medium">Source:</span>
                    @if($sourceUrl)
                        <a
                            href="{{ $sourceUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 font-medium text-yak-orange hover:text-yak-orange-warm transition-colors"
                            data-testid="source-link"
                        >
                            <span>{{ ucfirst($task->source) }}</span>
                            <flux:icon.arrow-top-right-on-square class="!size-3 opacity-70" />
                        </a>
                    @else
                        <span class="text-yak-slate">{{ ucfirst($task->source) }}</span>
                    @endif
                </span>
            @endif
            @if($task->model_used)
                <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                    <flux:icon.cpu-chip class="!size-3.5" />
                    <span class="font-medium">Model:</span>
                    <span class="text-yak-slate">{{ $task->model_used }}</span>
                </span>
            @endif
            @if($task->num_turns)
                <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                    <flux:icon.arrow-path-rounded-square class="!size-3.5" />
                    <span class="font-medium">Turns:</span>
                    <span class="text-yak-slate">{{ $task->num_turns }}</span>
                </span>
            @endif
            <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                <flux:icon.clock class="!size-3.5" />
                <span class="font-medium">Duration:</span>
                <span class="text-yak-slate">{{ \App\Livewire\Tasks\Support\TaskStyling::formatDuration($task->duration_ms) }}</span>
            </span>
            @if($task->cost_usd)
                <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                    <flux:icon.currency-dollar class="!size-3.5" />
                    <span class="font-medium">Cost:</span>
                    <span class="text-yak-slate">${{ number_format((float) $task->cost_usd, 2) }}</span>
                </span>
            @endif
            @if($task->branch_name && ! $isAnsweredFix)
                <span class="inline-flex items-center gap-1.5 text-xs text-yak-blue">
                    <flux:icon.code-bracket-square class="!size-3.5" />
                    <span class="font-medium">Branch:</span>
                    <code class="font-mono text-[11px] text-yak-slate">{{ $task->branch_name }}</code>
                </span>
            @endif

            <button
                type="button"
                wire:click="toggleDetailedView"
                class="ml-auto rounded-lg border border-[rgba(200,184,154,0.4)] px-2.5 py-1 text-xs font-medium text-yak-blue transition-colors hover:bg-[rgba(245,240,232,0.5)]"
                data-testid="detailed-view-toggle"
            >
                {{ $detailedView ? 'Condensed' : 'Detailed' }}
            </button>
        </div>
    </div>
</div>
