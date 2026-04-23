<x-preview-shell title="Preview not found" :failed="true">
    <h1>No preview here</h1>
    <p>Yak doesn't have a preview for <span class="host">{{ $hostname }}</span>.</p>
    <p>The branch may have been deleted, or this link might be stale.</p>
    <a class="cta" href="{{ config('app.url') }}">
        Open Yak dashboard <span class="arrow">&rarr;</span>
    </a>
</x-preview-shell>
