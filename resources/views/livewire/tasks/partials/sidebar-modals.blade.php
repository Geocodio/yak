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
latest-media section above. When the Remotion render has landed, the raw
recording is followed by the rendered walkthrough. --}}
<flux:modal wire:model.self="lightboxOpen" name="media-lightbox" class="!max-w-2xl" data-testid="media-lightbox">
    @php $lightboxArtifact = $this->lightboxArtifact; @endphp
    @if($lightboxArtifact)
        <div class="mb-3">
            <flux:heading size="lg">{{ $lightboxArtifact->filename }}</flux:heading>
        </div>

        @if($lightboxArtifact->type === 'video')
            <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                <video controls preload="metadata" class="w-full" src="{{ $lightboxArtifact->signedUrl() }}"></video>
            </div>

            @if($task->mode !== \App\Enums\TaskMode::Review && $this->walkthroughCut)
                <div class="mt-4 border-t border-[rgba(200,184,154,0.3)] pt-4">
                    @include('livewire.tasks.partials.walkthrough-player', [
                        'walkthroughUrl' => $this->walkthroughCut->signedUrl(),
                        'chapters' => $this->chapters,
                        'seekSeconds' => null,
                    ])
                </div>
            @endif
        @else
            <a href="{{ $lightboxArtifact->signedUrl() }}" target="_blank" rel="noopener noreferrer" class="block">
                <img src="{{ $lightboxArtifact->signedUrl() }}" alt="{{ $lightboxArtifact->filename }}" class="w-full rounded-[14px] border border-[rgba(200,184,154,0.4)] object-contain" />
            </a>
        @endif
    @endif
</flux:modal>
