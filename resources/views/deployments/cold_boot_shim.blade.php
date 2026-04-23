<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ ($failed ?? false) ? 'Preview unavailable' : 'Waking preview' }}</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #fafafa; color: #333; }
        .card { max-width: 420px; padding: 32px; text-align: center; }
        .spinner { width: 40px; height: 40px; margin: 0 auto 16px; border: 3px solid #eee; border-top-color: #555; border-radius: 50%; animation: spin 0.9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        code { background: #eee; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="card">
    @if ($failed ?? false)
        <h1>Preview unavailable</h1>
        <p>This preview failed to start: <code>{{ $reason }}</code></p>
        <p>An operator will look at it in the Yak dashboard.</p>
    @else
        <div class="spinner"></div>
        <h1>Waking preview</h1>
        <p>Starting the container for <code>{{ $deployment->hostname }}</code>. This page will reload when it's ready.</p>
    @endif
</div>
@unless ($failed ?? false)
    <script>
        async function poll() {
            try {
                const r = await fetch('/internal/deployments/status?host=' + encodeURIComponent({!! json_encode($deployment->hostname) !!}));
                if (r.ok) {
                    const body = await r.json();
                    if (body.state === 'ready') { location.reload(); return; }
                    if (body.state === 'failed') { location.reload(); return; }
                }
            } catch (e) { /* ignore */ }
            setTimeout(poll, 2000);
        }
        setTimeout(poll, 2000);
    </script>
@endunless
</body>
</html>
