<x-preview-shell
    :title="($failed ?? false) ? 'Preview unavailable' : 'Waking preview'"
    :failed="$failed ?? false"
    :auto-refresh="!($failed ?? false)"
>
    @if ($failed ?? false)
        <h1>This preview didn't come up</h1>
        <p>Yak tried to boot the sandbox for <span class="host">{{ $deployment->hostname }}</span> and hit a snag.</p>
        <div class="reason">{{ $reason }}</div>
        <a class="cta" href="{{ config('app.url') . '/deployments/' . $deployment->id }}">
            Open in Yak dashboard <span class="arrow">&rarr;</span>
        </a>
        <p class="tagline">Or ping whoever pushed this branch &mdash; they'll know what's up.</p>
    @else
        <h1>Waking up&hellip;</h1>
        <p>Yak is starting the sandbox for <span class="host">{{ $deployment->hostname }}</span>. This page refreshes on its own.</p>
    @endif
</x-preview-shell>
