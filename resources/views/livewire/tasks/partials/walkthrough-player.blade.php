{{--
    The rendered walkthrough: player, chapter list, transcript. Laid out to
    fill the height it is given -- the video takes every pixel the viewport
    allows, and the chapters/transcript rail scrolls beside it.

    The whole block is `wire:ignore`d so the task page's polling never
    resets a playhead, a chapter highlight, or a scroll position mid-watch;
    all of the interaction is therefore Alpine-only.

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
    class="flex min-h-0 flex-col gap-4 lg:h-full lg:flex-row"
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
    x-init="$nextTick(() => { const p = $refs.player; if (seekTo === null || ! p) return; if (p.readyState >= 1) { seek(seekTo); return; } p.addEventListener('loadedmetadata', () => seek(seekTo), { once: true }); })"
>
    {{-- The frame does not paint a background of its own: the video sits
         centred at its natural aspect, so a 16:9 cut in a tall column
         never grows black bars around itself. --}}
    <div class="flex shrink-0 items-center justify-center lg:min-h-0 lg:min-w-0 lg:flex-1 lg:shrink" data-testid="walkthrough-cut">
        <video
            x-ref="player"
            @timeupdate="sync()"
            controls
            autofocus
            preload="metadata"
            class="max-h-full max-w-full rounded-[14px] border border-[rgba(200,184,154,0.4)] bg-black"
            src="{{ $walkthroughUrl }}"
        ></video>
    </div>

    <aside class="flex min-h-0 w-full flex-col gap-4 lg:w-[340px] lg:shrink-0">
        @if(count($chapters) > 0)
            <div class="flex min-h-0 shrink-0 flex-col lg:max-h-[45%]">
                <h3 class="mb-2 text-xs font-medium uppercase tracking-wider text-yak-blue">Chapters</h3>
                <ul class="min-h-0 space-y-1 overflow-y-auto pr-1" data-testid="walkthrough-chapters">
                    @foreach($chapters as $index => $chapter)
                        <li>
                            <button
                                type="button"
                                @click="seek({{ $chapter['startSeconds'] }})"
                                :class="current === {{ $index }} ? 'bg-[rgba(212,145,94,0.16)] text-[#a5642f]' : 'text-yak-slate hover:bg-[rgba(200,184,154,0.2)]'"
                                :aria-current="current === {{ $index }} ? 'true' : 'false'"
                                class="flex w-full cursor-pointer items-baseline gap-2 rounded-lg px-2 py-1.5 text-left text-sm"
                                data-testid="walkthrough-chapter-{{ $index }}"
                            >
                                <span class="font-mono text-[11px] text-yak-tan">{{ \App\Livewire\Tasks\TaskDetail::formatTimestamp($chapter['startSeconds']) }}</span>
                                <span class="flex-1">{{ $chapter['title'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(count($transcriptLines) > 0)
            <div class="flex min-h-0 flex-1 flex-col" data-testid="walkthrough-transcript">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-xs font-medium uppercase tracking-wider text-yak-blue">Transcript</h3>
                    <button
                        type="button"
                        @click="copyTranscript()"
                        class="cursor-pointer rounded-lg px-2 py-1 text-[11px] font-medium text-yak-orange hover:bg-[rgba(212,145,94,0.12)]"
                        data-testid="walkthrough-copy-transcript"
                    >
                        <span x-show="! copied">Copy</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
                <ul class="min-h-0 flex-1 space-y-1 overflow-y-auto pr-1">
                    @foreach($transcriptLines as $line)
                        <li>
                            <button
                                type="button"
                                @click="seek({{ $line['startSeconds'] }})"
                                class="flex w-full cursor-pointer items-baseline gap-2 rounded-lg px-2 py-1 text-left text-xs text-yak-slate hover:bg-[rgba(200,184,154,0.2)]"
                                data-testid="walkthrough-transcript-line"
                            >
                                <span class="font-mono text-[11px] text-yak-tan">{{ $line['label'] }}</span>
                                <span class="flex-1">{{ $line['say'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </aside>
</div>
