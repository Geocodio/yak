{{--
    The rendered walkthrough: player, chapter list, transcript. The whole
    block is `wire:ignore`d so the task page's polling never resets a
    playhead, a chapter highlight, or a scroll position mid-watch; all of
    the interaction is therefore Alpine-only.

    Expects: $walkthroughUrl (string), $chapters (array as returned by
    TaskDetail::chapters()), $seekSeconds (int|null).
--}}
@php
    $transcriptLines = [];

    foreach ($chapters as $chapter) {
        foreach ($chapter['shots'] as $shot) {
            $transcriptLines[] = [
                'startSeconds' => $shot['startSeconds'],
                'label' => \App\Livewire\Tasks\TaskDetail::formatTimestamp($shot['startSeconds']),
                'say' => $shot['say'],
            ];
        }
    }
@endphp

<div
    wire:ignore
    data-testid="walkthrough-player"
    x-data="{
        chapters: @js(array_map(fn ($chapter) => ['title' => $chapter['title'], 'startSeconds' => $chapter['startSeconds']], $chapters)),
        lines: @js($transcriptLines),
        current: 0,
        copied: false,
        seekTo: {{ $seekSeconds === null ? 'null' : (int) $seekSeconds }},
        seek(seconds) {
            const player = this.$refs.player;

            if (! player) {
                return;
            }

            player.currentTime = seconds;
            player.play().catch(() => {});
        },
        sync() {
            const time = this.$refs.player?.currentTime ?? 0;
            let index = 0;

            this.chapters.forEach((chapter, i) => {
                if (time + 0.25 >= chapter.startSeconds) {
                    index = i;
                }
            });

            this.current = index;
        },
        copyTranscript() {
            const text = this.lines.map((line) => line.say).join('\n');

            navigator.clipboard?.writeText(text);
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 2000);
        },
    }"
    x-init="
        if (seekTo !== null) {
            $refs.player.addEventListener('loadedmetadata', () => seek(seekTo), { once: true });
        }
    "
>
    <div class="flex flex-col gap-3 md:flex-row">
        <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)] md:flex-1" data-testid="walkthrough-cut">
            <video
                x-ref="player"
                @timeupdate="sync()"
                controls
                preload="metadata"
                class="w-full"
                src="{{ $walkthroughUrl }}"
            ></video>
            <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">
                <a href="{{ $walkthroughUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">Walkthrough</a>
            </div>
        </div>

        @if(count($chapters) > 0)
            <ul class="max-h-[240px] w-full shrink-0 space-y-1 overflow-y-auto md:w-52" data-testid="walkthrough-chapters">
                @foreach($chapters as $index => $chapter)
                    <li>
                        <button
                            type="button"
                            @click="seek({{ $chapter['startSeconds'] }})"
                            :class="current === {{ $index }} ? 'bg-[rgba(212,145,94,0.16)] text-[#a5642f]' : 'text-yak-slate hover:bg-[rgba(200,184,154,0.2)]'"
                            class="flex w-full items-baseline gap-2 rounded-lg px-2 py-1.5 text-left text-xs"
                            data-testid="walkthrough-chapter-{{ $index }}"
                        >
                            <span class="font-mono text-[11px] text-yak-tan">{{ \App\Livewire\Tasks\TaskDetail::formatTimestamp($chapter['startSeconds']) }}</span>
                            <span class="flex-1">{{ $chapter['title'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
