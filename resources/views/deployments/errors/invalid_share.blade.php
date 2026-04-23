<x-preview-shell title="Share link invalid" :failed="true">
    <h1>This share link isn't valid</h1>
    <p>The link for <span class="host">{{ $hostname }}</span> has expired or was revoked.</p>
    <p>Ask whoever sent it for a fresh one.</p>
    <a class="cta" href="{{ config('app.url') }}">
        Open Yak dashboard <span class="arrow">&rarr;</span>
    </a>
</x-preview-shell>
