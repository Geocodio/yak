{{--
    Walkthrough card: where the video render stands for this task, plus a
    way into the player. Rendered from the sidebar partial, which is
    rendered twice (desktop column + mobile drawer), so it must stay
    free of element ids.

    Expects: $task (App\Models\YakTask, root task).
--}}
@php
    $walkthroughStatus = $this->renderStatus;
    $walkthroughPreview = $this->walkthroughPreview;
    $walkthroughPreviewUrl = $this->previewUrl($walkthroughPreview);
    $chipClasses = match ($walkthroughStatus->state) {
        \App\Livewire\Tasks\Support\VideoRenderStatus::Ready => 'bg-[rgba(93,140,110,0.15)] text-[#3f6b50]',
        \App\Livewire\Tasks\Support\VideoRenderStatus::Failed => 'bg-[rgba(190,86,74,0.14)] text-[#a3382c]',
        \App\Livewire\Tasks\Support\VideoRenderStatus::Rendering => 'bg-[rgba(212,145,94,0.16)] text-[#a5642f]',
        default => 'bg-[rgba(200,184,154,0.25)] text-yak-blue',
    };
@endphp

@if($walkthroughStatus->state !== \App\Livewire\Tasks\Support\VideoRenderStatus::None)
    <div class="rounded-2xl border border-[rgba(200,184,154,0.4)] bg-white p-4" data-testid="walkthrough-card">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="text-xs font-medium uppercase tracking-wider text-yak-blue">Walkthrough</h2>
            <span
                class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chipClasses }}"
                data-testid="walkthrough-status-{{ $walkthroughStatus->state }}"
            >{{ $walkthroughStatus->label() }}</span>
        </div>

        @if($walkthroughStatus->state === \App\Livewire\Tasks\Support\VideoRenderStatus::Ready && $this->walkthroughCut)
            <button
                type="button"
                wire:click="openMediaLightbox({{ $this->walkthroughCut->id }})"
                class="block w-full overflow-hidden rounded-[10px] border border-[rgba(200,184,154,0.45)] text-left transition-shadow hover:shadow-[0_4px_10px_rgba(61,79,95,0.08)]"
                data-testid="walkthrough-open"
            >
                @if($walkthroughPreviewUrl)
                    <img src="{{ $walkthroughPreviewUrl }}" alt="Walkthrough preview" loading="lazy" class="h-[120px] w-full object-cover" />
                @endif
                <span class="block bg-yak-cream-dark px-2 py-1 text-[11px] text-yak-blue">Watch the walkthrough</span>
            </button>
        @elseif($walkthroughStatus->state === \App\Livewire\Tasks\Support\VideoRenderStatus::Rendering)
            <p class="text-xs text-yak-slate">The cut is being rendered; this card updates on its own.</p>
        @elseif($walkthroughStatus->state === \App\Livewire\Tasks\Support\VideoRenderStatus::Failed)
            <p class="text-xs text-yak-slate" data-testid="walkthrough-error">{{ $walkthroughStatus->error ?? 'The render failed without a recorded reason.' }}</p>
        @endif
    </div>
@endif
