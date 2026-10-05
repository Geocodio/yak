@props([
    'title' => 'Yak',
    'failed' => false,
    'autoRefresh' => false,
    'mascot' => 'mascot.png',
    'sleeping' => false,
])

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} &mdash; Yak</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    @if ($autoRefresh)
        {{-- Fallback while the sandbox is warming. JS below polls more
             aggressively and reloads the moment the wake endpoint stops
             returning the shim — the meta-refresh just guarantees the
             page recovers if JS is disabled or breaks. --}}
        <meta http-equiv="refresh" content="15">
    @endif
    <link rel="icon" href="{{ config('app.url') }}/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="{{ config('app.url') }}/favicon.ico" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@400;500&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        :root {
            --yak-slate:       #3d4f5f;
            --yak-blue:        #6b8fa3;
            --yak-orange:      #c4744a;
            --yak-green:       #7a8c5e;
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
            display: flex;
            flex-direction: column;
            padding: 28px 32px;
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
        .topbar {
            position: relative; z-index: 1;
            display: flex; justify-content: space-between; align-items: center;
        }
        .mark {
            font-family: 'Instrument Serif', Georgia, serif;
            font-size: 28px; letter-spacing: -0.04em; line-height: 1;
            color: var(--yak-slate);
        }
        .mark .a { font-style: italic; color: var(--yak-orange); }
        .topbar .label {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 12.5px;
            color: color-mix(in srgb, var(--yak-slate) 60%, transparent);
        }
        .center {
            position: relative; z-index: 1;
            flex: 1;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center;
            max-width: 560px; width: 100%; margin: 0 auto;
            padding: 24px 0;
        }
        .mascot-wrap {
            position: relative;
            width: 240px;
            max-width: 100%;
            margin: 0 auto 18px;
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
        .mascot.sleeping {
            animation: none;
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
            font-size: 40px;
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
        .hostchip {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px 8px 10px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid color-mix(in srgb, var(--yak-tan) 45%, transparent);
            box-shadow: 0 1px 0 rgba(61, 79, 95, 0.04);
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
            color: var(--yak-slate);
            margin: 4px 0 26px;
            max-width: 100%;
            word-break: break-all;
        }
        .hostchip .dot {
            flex-shrink: 0;
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--yak-orange);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--yak-orange) 18%, transparent);
            animation: pulse 1.6s ease-in-out infinite;
        }
        @keyframes pulse {
            50% { box-shadow: 0 0 0 7px color-mix(in srgb, var(--yak-orange) 6%, transparent); }
        }
        .meter { width: 280px; max-width: 100%; }
        .meter .track { height: 6px; border-radius: 6px; background: var(--yak-cream-dark); overflow: hidden; }
        .meter .track span {
            display: block; height: 100%; width: 38%; border-radius: 6px;
            background: linear-gradient(90deg, var(--yak-blue), var(--yak-green));
            animation: slide 1.8s ease-in-out infinite;
        }
        @keyframes slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(270%); } }
        .meter .legend {
            display: flex; justify-content: space-between; margin-top: 9px;
            font-size: 12.5px;
            color: color-mix(in srgb, var(--yak-slate) 60%, transparent);
        }
        .meter .legend b {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-weight: 400; color: var(--yak-slate);
        }
        @media (prefers-reduced-motion: reduce) {
            .hostchip .dot, .meter .track span, .mascot { animation: none; }
            .meter .track span { width: 38%; margin-left: 31%; }
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
<div class="topbar">
    <span class="mark">Y<span class="a">a</span>k</span>
    <span class="label">preview</span>
</div>
<div class="center">
    <div class="mascot-wrap">
        @if ($sleeping && ! $failed)
            {{-- The WebP carries the baked snooze animation; the PNG is the static fallback. --}}
            <picture>
                <source media="(prefers-reduced-motion: reduce)" srcset="{{ config('app.url') }}/{{ $mascot }}">
                <source srcset="{{ config('app.url') }}/{{ \Illuminate\Support\Str::replaceLast('.png', '.webp', $mascot) }}" type="image/webp">
                <img class="mascot sleeping" src="{{ config('app.url') }}/{{ $mascot }}" alt="" aria-hidden="true">
            </picture>
        @else
            <img class="mascot{{ $failed ? ' failed' : '' }}"
                 src="{{ config('app.url') }}/{{ $mascot }}"
                 alt=""
                 aria-hidden="true">
        @endif
    </div>
    {{ $slot }}
</div>
</body>
</html>
