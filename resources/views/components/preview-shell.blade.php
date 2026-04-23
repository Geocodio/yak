@props([
    'title' => 'Yak',
    'failed' => false,
    'autoRefresh' => false,
])

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} &mdash; Yak</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    @if ($autoRefresh)
        {{-- Auto-refresh while the sandbox is warming. Once wake
             returns 200, Caddy proxies to the sandbox and the page is
             replaced by whatever the preview app serves. --}}
        <meta http-equiv="refresh" content="3">
    @endif
    <link rel="icon" href="{{ config('app.url') }}/favicon.ico" sizes="any">
    <link rel="icon" href="{{ config('app.url') }}/favicon.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif&family=Outfit:wght@400;500&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        :root {
            --yak-slate:       #3d4f5f;
            --yak-blue:        #6b8fa3;
            --yak-orange:      #c4744a;
            --yak-orange-warm: #d4915e;
            --yak-tan:         #c8b89a;
            --yak-cream:       #f5f0e8;
            --yak-cream-dark:  #e8e0d2;
            --yak-danger:      #b85450;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; min-height: 100vh; }
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--yak-slate);
            background: var(--yak-cream);
            display: grid;
            place-items: center;
            padding: 40px 20px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            font-size: 16px;
            line-height: 1.55;
        }
        body::before {
            content: '';
            position: fixed; inset: 0;
            pointer-events: none;
            opacity: 0.035;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            z-index: 0;
        }
        .card {
            position: relative; z-index: 1;
            max-width: 560px;
            width: 100%;
            text-align: center;
        }
        .mascot-wrap {
            position: relative;
            width: 260px;
            margin: 0 auto 28px;
        }
        .mascot-wrap::before {
            content: '';
            position: absolute; inset: -24px;
            background: radial-gradient(ellipse at center, color-mix(in srgb, var(--yak-tan) 35%, transparent), transparent 65%);
            filter: blur(30px);
            z-index: 0;
        }
        .mascot {
            position: relative;
            display: block;
            width: 100%;
            height: auto;
            filter: drop-shadow(0 18px 24px rgba(92, 74, 58, 0.15));
            z-index: 1;
            animation: breathe 2.8s ease-in-out infinite;
        }
        .mascot.failed {
            animation: none;
            filter: saturate(0.55) drop-shadow(0 18px 24px rgba(92, 74, 58, 0.15));
        }
        @keyframes breathe {
            0%, 100% { transform: translateY(0)    scale(1);     }
            50%      { transform: translateY(-4px) scale(1.012); }
        }
        h1 {
            font-family: 'Instrument Serif', Georgia, serif;
            font-weight: 400;
            font-size: 42px;
            letter-spacing: -0.02em;
            color: var(--yak-slate);
            margin: 0 0 14px;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            color: color-mix(in srgb, var(--yak-slate) 75%, transparent);
            margin: 0 0 12px;
        }
        .host {
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
            background: var(--yak-cream-dark);
            padding: 2px 8px;
            border-radius: 6px;
            color: var(--yak-slate);
        }
        .reason {
            margin: 18px 0 22px;
            padding: 14px 16px;
            border: 1px solid rgba(184, 84, 80, 0.22);
            background: rgba(184, 84, 80, 0.06);
            color: var(--yak-danger);
            border-radius: 14px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px;
            text-align: left;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .cta {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 11px 22px;
            border-radius: 14px;
            background: var(--yak-orange);
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            font-size: 14.5px;
            transition: background 0.2s ease, transform 0.2s ease;
            box-shadow: 0 4px 14px rgba(196, 116, 74, 0.28);
        }
        .cta:hover {
            background: var(--yak-orange-warm);
            transform: translateY(-1px);
        }
        .cta .arrow { transition: transform 0.2s ease; }
        .cta:hover .arrow { transform: translateX(3px); }
        .tagline {
            margin-top: 20px;
            font-size: 13.5px;
            color: color-mix(in srgb, var(--yak-slate) 55%, transparent);
        }
    </style>
</head>
<body>
<div class="card">
    <div class="mascot-wrap">
        <img class="mascot{{ $failed ? ' failed' : '' }}"
             src="{{ config('app.url') }}/mascot.png"
             alt=""
             aria-hidden="true">
    </div>
    {{ $slot }}
</div>
</body>
</html>
