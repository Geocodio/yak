{{--
    Sidebar modals: the transcript overlay (the full activity log, opened
    from a row or the Activity header) and the media lightbox
    (screenshots/videos clicked from a thread turn or the latest-media
    section).

    Rendered exactly once by task-detail.blade.php, outside the sidebar
    partial itself, since the sidebar partial is rendered twice (desktop
    column + mobile drawer) and these modals must not be duplicated.

    Expects: $task (App\Models\YakTask, root task).
--}}
{{-- Transcript overlay: the full activity log as a near full-bleed
     two-pane workspace. Left rail is the same list as the sidebar's
     preview strip; right pane is the selected entry in full. Replaces the
     old right-hand flyout, which shrink-wrapped to each entry's content
     and covered the very list it was opened from. --}}
@include('livewire.tasks.partials.transcript')

{{-- Media lightbox: screenshots/videos clicked from a thread turn or the
latest-media section above. Sized like the transcript overlay -- a near
full-bleed sheet -- so the video plays at the size the viewport allows
instead of in a small centred box. When the Remotion render has landed,
the rendered walkthrough is what plays. --}}
<flux:modal
    wire:model.self="lightboxOpen"
    name="media-lightbox"
    :closable="false"
    class="h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none overflow-hidden rounded-2xl !bg-yak-cream !p-0"
    data-testid="media-lightbox"
>
    @php $lightboxArtifact = $this->lightboxArtifact; @endphp
    @if($lightboxArtifact)
        @php
            $isVideo = in_array($lightboxArtifact->type, ['video', 'video_cut'], true);
            // A Review-mode task never gets the player, so its raw video
            // must still play here or the lightbox is empty.
            $showsPlayer = $isVideo && $task->mode !== \App\Enums\TaskMode::Review && $this->walkthroughCut;
        @endphp

        <div class="flex h-full flex-col">
            {{-- Bar: what you're looking at, and the way out --}}
            <div class="flex items-center gap-3 border-b border-[rgba(200,184,154,0.5)] px-5 py-3">
                <h2 class="truncate text-sm font-semibold text-yak-slate">
                    {{ $showsPlayer ? 'Walkthrough' : $lightboxArtifact->filename }}
                </h2>
                @if($showsPlayer && $lightboxArtifact->id !== $this->walkthroughCut->id)
                    <a
                        href="{{ $lightboxArtifact->signedUrl() }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="shrink-0 text-xs font-medium text-yak-orange hover:text-yak-orange-warm"
                        data-testid="lightbox-raw-recording"
                    >
                        Raw recording
                    </a>
                @endif

                <button
                    type="button"
                    wire:click="closeMediaLightbox"
                    class="ml-auto cursor-pointer rounded-lg border border-[rgba(200,184,154,0.4)] px-2.5 py-1 text-xs font-medium text-yak-blue transition-colors hover:bg-[rgba(245,240,232,0.7)] hover:text-yak-slate"
                    data-testid="lightbox-close"
                >
                    Close <span class="ml-1 font-mono text-[10px] text-yak-tan">esc</span>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-4">
                @if($showsPlayer)
                    @include('livewire.tasks.partials.walkthrough-player', [
                        'walkthroughUrl' => $this->walkthroughCut->signedUrl(),
                        'chapters' => $this->chapters,
                        'seekSeconds' => $this->seekSeconds,
                    ])
                @elseif($isVideo)
                    <div class="flex h-full items-center justify-center" wire:ignore>
                        <video controls preload="metadata" class="max-h-full max-w-full rounded-[14px] border border-[rgba(200,184,154,0.4)] bg-black" src="{{ $lightboxArtifact->signedUrl() }}"></video>
                    </div>
                @else
                    <div class="flex h-full flex-col items-center justify-center gap-3">
                        <a href="{{ $lightboxArtifact->signedUrl() }}" target="_blank" rel="noopener noreferrer" class="flex min-h-0 flex-1 items-center justify-center">
                            <img src="{{ $lightboxArtifact->signedUrl() }}" alt="{{ $lightboxArtifact->caption ?? $lightboxArtifact->filename }}" class="max-h-full max-w-full rounded-[14px] border border-[rgba(200,184,154,0.4)] object-contain" />
                        </a>
                        @if($lightboxArtifact->caption ?? null)
                            <p class="shrink-0 text-center text-xs italic text-yak-slate" data-testid="artifact-caption">{{ $lightboxArtifact->caption }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</flux:modal>
