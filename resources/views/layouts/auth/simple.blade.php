<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light">

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <x-app-icons />

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">

        <style>
            :root {
                --yak-slate: #3d4f5f;
                --yak-blue: #6b8fa3;
                --yak-green: #7a8c5e;
                --yak-orange: #c4744a;
                --yak-cream: #f5f0e8;
                color-scheme: light;
            }
            * { box-sizing: border-box; }
            html, body { margin: 0; min-height: 100vh; }
            body {
                position: relative;
                font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
                color: var(--yak-slate);
                background: var(--yak-cream);
                -webkit-font-smoothing: antialiased;
            }
            body::before {
                content: '';
                position: fixed;
                inset: 0;
                pointer-events: none;
                z-index: 2;
                opacity: 0.035;
                background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
                background-size: 256px 256px;
            }
            .page {
                position: relative;
                z-index: 1;
                display: flex;
                flex-direction: column;
                min-height: 100svh;
                padding: 28px 32px;
            }
            .mark {
                align-self: flex-start;
                font-family: 'Instrument Serif', Georgia, serif;
                font-size: 28px;
                letter-spacing: -0.04em;
                line-height: 1;
                color: var(--yak-slate);
                text-decoration: none;
            }
            .mark .a, .title .a { font-style: italic; color: var(--yak-orange); }
            .center {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                padding: 24px 0;
            }
            .stage { width: 100%; max-width: 440px; aspect-ratio: 16 / 9; }
            .stage video {
                display: block; width: 100%; height: 100%; object-fit: cover;
                {{-- Fades the frame edges into the page so no colour step can show as a box. --}}
                -webkit-mask-image: radial-gradient(ellipse 50% 50% at 50% 50%, #000 70%, transparent 100%);
                mask-image: radial-gradient(ellipse 50% 50% at 50% 50%, #000 70%, transparent 100%);
            }
            .title {
                font-family: 'Instrument Serif', Georgia, serif;
                font-weight: 400;
                font-size: 46px;
                letter-spacing: -0.03em;
                line-height: 1;
                margin: 6px 0 8px;
            }
            .eyebrow {
                margin: 0 0 26px;
                font-size: 12px;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: var(--yak-blue);
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                width: 100%;
                max-width: 320px;
                padding: 14px 22px;
                border-radius: 12px;
                font-size: 15px;
                font-weight: 500;
                text-decoration: none;
                color: #fff;
                background: var(--yak-slate);
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            .btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 18px rgba(61, 79, 95, 0.22);
                background: linear-gradient(135deg, var(--yak-slate) 0%, var(--yak-blue) 35%, var(--yak-green) 70%, var(--yak-orange) 100%);
            }
            .btn svg { width: 18px; height: 18px; flex-shrink: 0; }
            .error { margin: 16px 0 0; max-width: 320px; font-size: 13px; color: #b85450; }
            @media (max-width: 480px) {
                .page { padding: 20px 16px; }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <a href="{{ url('/') }}" class="mark">Y<span class="a">a</span>k</a>
            <div class="center">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
