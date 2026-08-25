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
latest-media section above. Videos additionally surface the Reviewer/Director
cut picker + generation button, absorbed from the old video-walkthrough card. --}}
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

            <div class="mt-4 border-t border-[rgba(200,184,154,0.3)] pt-4">
                @if($task->mode !== \App\Enums\TaskMode::Review)
                @if($this->reviewerCut)
                    @php $reviewerUrl = $this->reviewerCut->signedUrl(); @endphp
                    <div class="mb-3 overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                        <video controls preload="metadata" class="w-full" src="{{ $reviewerUrl }}"></video>
                        <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">
                            <a href="{{ $reviewerUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">Reviewer Cut</a>
                        </div>
                    </div>
                @endif

                @if($this->directorCutStatus === 'ready' && $this->directorCut)
                    @php $directorUrl = $this->directorCut->signedUrl(); @endphp
                    <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                        <video controls preload="metadata" class="w-full" src="{{ $directorUrl }}"></video>
                        <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">
                            <a href="{{ $directorUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">Director's Cut</a>
                        </div>
                    </div>
                @elseif($this->directorCutStatus === 'queued' || $this->directorCutStatus === 'rendering')
                    <div class="flex items-center gap-3 text-sm text-yak-blue" data-testid="director-cut-progress">
                        <flux:icon.loading variant="mini" class="size-4" />
                        <span>{{ $this->directorCutStatus === 'queued' ? 'Queued…' : "Rendering Director's Cut…" }}</span>
                    </div>
                @elseif($this->directorCutStatus === 'failed')
                    <div class="flex items-center gap-3 text-sm text-yak-danger" data-testid="director-cut-failed">
                        <span>Director's Cut render failed.</span>
                        <flux:button variant="ghost" size="sm" wire:click="generateDirectorCut">Retry</flux:button>
                    </div>
                @elseif($this->canGenerateDirectorCut)
                    <flux:button variant="primary" icon="sparkles" wire:click="generateDirectorCut" data-testid="generate-director-cut">
                        Generate Director's Cut
                    </flux:button>
                    <p class="mt-2 text-xs text-yak-blue">Spins up a fresh sandbox against the PR branch. Takes ~2–3 min.</p>
                @endif
                @endif
            </div>
        @else
            <a href="{{ $lightboxArtifact->signedUrl() }}" target="_blank" rel="noopener noreferrer" class="block">
                <img src="{{ $lightboxArtifact->signedUrl() }}" alt="{{ $lightboxArtifact->filename }}" class="w-full rounded-[14px] border border-[rgba(200,184,154,0.4)] object-contain" />
            </a>
        @endif
    @endif
</flux:modal>
