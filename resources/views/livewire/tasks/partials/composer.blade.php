{{--
    Unified state-aware composer. Branches on $composerState:
    'clarification' | 'steering' | 'follow_up' | 'disabled_failed' | 'disabled_closed'.

    Expects: $task, $composerText, $composerState, $head (the chain's
    current head task, used for the follow-up PR number).
--}}
@php
    $isActive = in_array($composerState, ['clarification', 'steering', 'follow_up'], true);

    $retryActionLabel = $task->mode === \App\Enums\TaskMode::Review ? 'Re-run review' : 'Retry';

    [$placeholder, $testid, $explanation] = match ($composerState) {
        'clarification' => ['Answer Yak…', 'clarification-reply-input', null],
        'steering' => ['Steer Yak — this will be picked up when the current run checks in…', 'steering-input', 'Queued until the current run finishes.'],
        'follow_up' => ['Reply to Yak — it will push changes to PR #' . ($head->pr_number ?? '?') . '…', 'follow-up-input', null],
        'disabled_failed' => $task->mode === \App\Enums\TaskMode::Research
            ? ["This research failed — click {$retryActionLabel} above to try again.", 'composer-input', "This research failed. Click {$retryActionLabel} above, or adjust the issue and re-assign Yak."]
            : ["This task failed — click {$retryActionLabel} above to try again.", 'composer-input', "This task failed. Click {$retryActionLabel} above, or mention Yak again with more context."],
        default => ['This conversation is closed — mention Yak again to start a new task.', 'composer-input', 'This conversation is closed — mention Yak again to start a new task.'],
    };

    $sendTestid = match ($composerState) {
        'clarification' => 'clarification-reply-submit',
        default => 'composer-send',
    };
@endphp
<div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)] {{ $isActive ? '' : 'opacity-60' }}" data-testid="composer">
    <textarea
        wire:model="composerText"
        rows="3"
        placeholder="{{ $placeholder }}"
        data-testid="{{ $testid }}"
        @unless($isActive) disabled @endunless
        class="w-full rounded-[14px] border border-[rgba(200,184,154,0.5)] bg-[#faf7f1] p-3 text-sm text-yak-slate focus:border-yak-orange focus:outline-none focus:ring-1 focus:ring-yak-orange disabled:cursor-not-allowed"
    ></textarea>
    @error('composerText')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-yak-blue">
            @if($explanation)
                {{ $explanation }}
            @elseif(in_array($task->source, ['slack', 'linear'], true))
                Replies here and in the {{ ucfirst($task->source) }} thread land in the same conversation.
            @endif
        </p>
        @if($isActive)
            <flux:button wire:click="sendMessage" variant="primary" size="sm" data-testid="{{ $sendTestid }}">
                Send
            </flux:button>
        @endif
    </div>
</div>
