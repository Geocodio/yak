@php
    $failed = in_array($outcome->state, ['failing', 'destroyed', 'no_manifest'], true);
@endphp
<x-preview-shell
    :title="$failed ? 'Preview unavailable' : 'Waking preview'"
    :failed="$failed"
    :sleeping="! $failed"
    :mascot="$failed ? 'mascot.png' : 'mascot-sleeping.png'"
    :auto-refresh="! $failed"
>
    @if ($outcome->state === 'destroyed')
        <h1>This sandbox was removed</h1>
        <p>Open the thread in Yak to restore it, then reload this page.</p>
    @elseif ($failed)
        <h1>This preview didn't come up</h1>
        <div class="reason" data-testid="preview-error">{{ $outcome->state === 'no_manifest' ? 'This repository has no .yak/preview.yml, so Yak does not know how to start a dev server.' : \App\Support\SecretRedactor::redact((string) $outcome->error) }}</div>
    @else
        <h1>{{ $outcome->state === 'waiting_for_slot' ? 'Waiting for a free sandbox' : 'Waking up the preview' }}</h1>
        <ul data-testid="preview-steps">
            @foreach ($outcome->steps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ul>
        <script>
            (function () {
                const startedAt = Date.now();
                const stepList = document.querySelector('[data-testid="preview-steps"]');
                let inFlight = false;

                async function probe() {
                    if (inFlight || document.hidden) return;
                    inFlight = true;
                    try {
                        const res = await fetch('/_yak/preview-status', { cache: 'no-store', credentials: 'include' });
                        if (!res.ok) return;
                        const status = await res.json();
                        if (status.state === 'ready' || status.state === 'failing') {
                            location.reload();
                            return;
                        }
                        if (stepList && status.steps && status.steps.length) {
                            stepList.replaceChildren(...status.steps.map((step) => {
                                const item = document.createElement('li');
                                item.textContent = step;
                                return item;
                            }));
                        }
                    } catch (_) {
                    } finally {
                        inFlight = false;
                        setTimeout(probe, Date.now() - startedAt < 3000 ? 250 : 1000);
                    }
                }

                setTimeout(probe, 250);
            })();
        </script>
    @endif
    <a class="cta" href="{{ config('app.url') }}">
        Open Yak dashboard <span class="arrow">&rarr;</span>
    </a>
</x-preview-shell>
