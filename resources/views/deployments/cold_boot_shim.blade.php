<x-preview-shell
    :title="($failed ?? false) ? 'Preview unavailable' : 'Waking preview'"
    :failed="$failed ?? false"
    :sleeping="!($failed ?? false)"
    :mascot="($failed ?? false) ? 'mascot.png' : 'mascot-sleeping.png'"
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
        <h1>Waking up<span class="dots" aria-hidden="true"><span></span><span></span><span></span></span></h1>
        <p>Yak is rousing the sandbox for <span class="host">{{ $deployment->hostname }}</span>.</p>
        <p>This usually takes under a minute &mdash; sit tight, the page reloads on its own.</p>
        <p class="progress-hint">No action needed. We'll bring you straight to the preview as soon as it's ready.</p>
        <script>
            // Quietly poll the same URL with HEAD requests. The wake
            // endpoint returns 425 while the sandbox is still warming;
            // any other status means Caddy is now proxying to the live
            // sandbox (or the wake itself failed). Either way, reload
            // so the user sees the real response instead of this shim.
            (function () {
                const deadline = Date.now() + 5 * 60 * 1000;
                let inFlight = false;
                async function probe() {
                    if (inFlight || document.hidden) return;
                    inFlight = true;
                    try {
                        const res = await fetch(window.location.pathname + window.location.search, {
                            method: 'HEAD',
                            cache: 'no-store',
                            credentials: 'include',
                            redirect: 'manual',
                        });
                        if (res.status !== 425) {
                            window.location.reload();
                            return;
                        }
                    } catch (_) {
                        // Network blip while the container's network stack
                        // settles. Ignore and let the next tick retry.
                    } finally {
                        inFlight = false;
                    }
                    if (Date.now() < deadline) {
                        setTimeout(probe, 2000);
                    }
                }
                setTimeout(probe, 2000);
            })();
        </script>
    @endif
</x-preview-shell>
