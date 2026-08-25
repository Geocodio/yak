{{--
    One labelled block of monospace detail in the log drawer — a command,
    a prompt, or captured output — with a copy button, since debugging a
    step usually ends with pasting it somewhere.

    Props:
      - $label: section label above the block. Omit for an unlabelled block.
      - $tone: text colour class for the label.
      - $text: the content. Nothing renders when it is blank.

    Extra classes land on the <pre>.
--}}
@props(['label' => null, 'tone' => 'text-yak-blue', 'text' => ''])
@php $content = (string) $text; @endphp
@if(trim($content) !== '')
    <div
        x-data="{
            copied: false,
            copy() {
                navigator.clipboard.writeText(@js($content)).then(() => {
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1500);
                });
            },
        }"
    >
        <div class="mb-1 flex items-center gap-2">
            @if($label)
                <span class="text-[11px] font-medium uppercase tracking-wider {{ $tone }}">{{ $label }}</span>
            @endif
            <button
                type="button"
                @click="copy()"
                class="ml-auto rounded-md px-1.5 py-0.5 font-mono text-[10px] text-[#8a8a8a] transition-colors hover:bg-white/10 hover:text-[#d4d4d4]"
                data-testid="log-block-copy"
            >
                <span x-show="! copied">Copy</span>
                <span x-show="copied" x-cloak class="text-yak-green">Copied</span>
            </button>
        </div>
        <pre {{ $attributes->class('overflow-x-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]') }}>{{ $content }}</pre>
    </div>
@endif
