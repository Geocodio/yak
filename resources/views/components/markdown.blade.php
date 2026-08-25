{{--
    Renders a Markdown string as formatted HTML inside Yak's prose styling.

    Agent-authored text (PR bodies, run summaries, review findings) arrives
    as Markdown, so it has to be parsed rather than echoed raw.

    Props:
      - $text: the Markdown source. Nothing renders when it is blank.
      - $compact: flatten heading sizes and paragraph margins, for previews
        that live inside a line clamp.

    Any extra classes passed on the tag are merged onto the wrapper.
--}}
@props(['text' => null, 'compact' => false])
@php
    $markdown = trim((string) $text);
    $proseClasses = 'prose prose-sm prose-yak max-w-none text-yak-slate prose-headings:text-yak-slate prose-a:text-yak-orange prose-a:hover:text-yak-orange-warm prose-strong:text-yak-slate prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-yak-slate prose-code:before:content-none prose-code:after:content-none dark:prose-code:bg-white/10';
@endphp
@if($markdown !== '')
    <div {{ $attributes->class([$proseClasses, 'prose-compact' => $compact]) }}>
        {!! \App\Support\Markdown::toHtml($markdown) !!}
    </div>
@endif
