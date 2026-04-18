<div
    wire:poll.5s="refreshFromEvent"
    class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]"
    data-testid="video-walkthrough-card"
>
    <h2 class="mb-4 text-lg font-medium text-yak-slate">Video walkthrough</h2>

    @if($this->reviewerCut)
        <div class="mb-4 overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]">
            <video controls preload="metadata" class="w-full max-w-xl" src="{{ $this->reviewerCut->signedUrl() }}"></video>
            <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">Reviewer Cut</div>
        </div>
    @else
        <p class="mb-4 text-sm text-yak-blue">No reviewer cut available yet.</p>
    @endif

    @if($this->directorCutStatus === 'ready' && $this->directorCut)
        <div class="mb-4 overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]">
            <video controls preload="metadata" class="w-full max-w-xl" src="{{ $this->directorCut->signedUrl() }}"></video>
            <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">Director's Cut</div>
        </div>
    @elseif($this->directorCutStatus === 'queued' || $this->directorCutStatus === 'rendering')
        <div class="mb-4 flex items-center gap-3 text-sm text-yak-blue" data-testid="director-cut-progress">
            <flux:icon.loading variant="mini" class="size-4" />
            <span>{{ $this->directorCutStatus === 'queued' ? 'Queued…' : "Rendering Director's Cut…" }}</span>
        </div>
    @elseif($this->directorCutStatus === 'failed')
        <div class="mb-4 flex items-center gap-3 text-sm text-yak-danger" data-testid="director-cut-failed">
            <span>Director's Cut render failed.</span>
            <flux:button variant="ghost" size="sm" wire:click="generateDirectorCut">Retry</flux:button>
        </div>
    @elseif($this->canGenerateDirectorCut)
        <flux:button variant="primary" icon="sparkles" wire:click="generateDirectorCut" data-testid="generate-director-cut">
            Generate Director's Cut
        </flux:button>
        <p class="mt-2 text-xs text-yak-blue">Spins up a fresh sandbox against the PR branch. Takes ~2–3 min.</p>
    @endif
</div>
