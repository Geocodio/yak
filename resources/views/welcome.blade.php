<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yak — Papercuts, handled.</title>
    <meta name="description" content="Yak is an autonomous coding agent for papercuts. It picks up small tasks from Slack, Linear, Sentry, and our CI and delivers reviewable pull requests — or answers questions about the codebase.">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --yak-slate:        #3d4f5f;
            --yak-brown:        #5c4a3a;
            --yak-blue:         #6b8fa3;
            --yak-blue-light:   #8fb3c4;
            --yak-green:        #7a8c5e;
            --yak-green-muted:  #9aaa7e;
            --yak-orange:       #c4744a;
            --yak-orange-warm:  #d4915e;
            --yak-tan:          #c8b89a;
            --yak-cream:        #f5f0e8;
            --yak-cream-dark:   #e8e0d2;
            --yak-danger:       #b85450;

            --font-sans:  'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-serif: 'Instrument Serif', 'Iowan Old Style', Georgia, serif;
            --font-mono:  'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;

            --radius-btn:  14px;
            --radius-card: 28px;
            --radius-sm:   8px;

            --shadow-1: 0 4px 6px rgba(61, 79, 95, 0.03), 0 12px 24px rgba(61, 79, 95, 0.06);
            --shadow-2: 0 4px 6px rgba(61, 79, 95, 0.03), 0 12px 24px rgba(61, 79, 95, 0.06), 0 32px 64px rgba(61, 79, 95, 0.08);
        }

        * { box-sizing: border-box; }
        html { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }

        body {
            margin: 0;
            font-family: var(--font-sans);
            color: var(--yak-slate);
            background: var(--yak-cream);
            line-height: 1.55;
            font-size: 17px;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            opacity: 0.035;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-repeat: repeat;
            background-size: 256px 256px;
        }

        main, header, footer, section { position: relative; z-index: 1; }

        .serif { font-family: var(--font-serif); font-weight: 400; letter-spacing: -0.01em; }
        .mono  { font-family: var(--font-mono); }
        .italic { font-style: italic; }

        .eyebrow {
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--yak-blue);
        }

        .caption {
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--yak-tan);
        }

        h1, h2, h3 { margin: 0; font-family: var(--font-serif); font-weight: 400; letter-spacing: -0.02em; color: var(--yak-slate); }
        p { margin: 0; }
        a  { color: inherit; }

        .wordmark {
            font-family: var(--font-serif);
            font-weight: 400;
            color: var(--yak-slate);
            letter-spacing: -0.04em;
            line-height: 0.85;
        }
        .wordmark .a { font-style: italic; color: var(--yak-orange); }

        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 0 auto;
            max-width: 460px;
        }
        .divider .line {
            flex: 1;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--yak-tan), transparent);
        }
        .divider .star {
            width: 18px; height: 18px;
            color: var(--yak-tan);
        }

        .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 28px;
        }

        section { padding: 110px 0; }

        header {
            padding: 28px 0 0;
        }
        .topbar {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar .mark {
            font-family: var(--font-serif);
            font-size: 28px;
            line-height: 1;
            letter-spacing: -0.04em;
            color: var(--yak-slate);
            text-decoration: none;
        }
        .topbar .mark .a { font-style: italic; color: var(--yak-orange); }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border-radius: var(--radius-btn);
            padding: 14px 22px;
            font-family: var(--font-sans);
            font-size: 15px;
            font-weight: 500;
            letter-spacing: 0.01em;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease, border-color .2s ease;
        }
        .btn-primary {
            background: var(--yak-slate);
            color: #fff;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-2);
            background: linear-gradient(135deg, var(--yak-slate) 0%, var(--yak-blue) 35%, var(--yak-green) 70%, var(--yak-orange) 100%);
        }
        .btn-ghost {
            color: var(--yak-slate);
            background: transparent;
            border-color: color-mix(in srgb, var(--yak-tan) 60%, transparent);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.55);
            border-color: var(--yak-tan);
        }
        .btn-compact {
            padding: 10px 18px;
            font-size: 14px;
        }
        .btn .arrow { transition: transform .2s; }
        .btn:hover .arrow { transform: translateX(3px); }

        .hero {
            padding: 80px 0 70px;
            position: relative;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 48px;
            align-items: center;
        }
        .hero h1 {
            font-size: clamp(68px, 11vw, 168px);
            line-height: 0.84;
            letter-spacing: -0.045em;
            margin: 18px 0 26px;
        }
        .hero h1 .a { font-style: italic; color: var(--yak-orange); }
        .hero .lede {
            font-family: var(--font-serif);
            font-size: clamp(22px, 2.3vw, 30px);
            line-height: 1.3;
            letter-spacing: -0.015em;
            color: var(--yak-slate);
            max-width: 560px;
            margin-bottom: 28px;
        }
        .hero .lede em {
            color: var(--yak-orange);
            font-style: italic;
        }
        .hero .subline {
            font-size: 16.5px;
            color: color-mix(in srgb, var(--yak-slate) 70%, transparent);
            max-width: 520px;
            line-height: 1.6;
        }
        .hero-cta {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 36px;
            flex-wrap: wrap;
        }
        .hero-cta .tagline {
            font-size: 13px;
            color: color-mix(in srgb, var(--yak-slate) 55%, transparent);
            letter-spacing: 0.02em;
        }

        .hero-mascot {
            position: relative;
            justify-self: center;
            cursor: default;
        }
        .hero-mascot .mascot-img {
            width: 100%;
            max-width: 520px;
            height: auto;
            filter: drop-shadow(0 30px 40px rgba(92, 74, 58, 0.18));
            position: relative;
            z-index: 2;
            transform-origin: 50% 70%;
            transition: translate 0.75s cubic-bezier(0.22, 1, 0.36, 1),
                        rotate   0.75s cubic-bezier(0.22, 1, 0.36, 1),
                        scale    0.75s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .hero-mascot::before {
            content: '';
            position: absolute;
            inset: -40px;
            background: radial-gradient(ellipse at center, color-mix(in srgb, var(--yak-tan) 35%, transparent), transparent 65%);
            z-index: -1;
            filter: blur(30px);
        }

        /* Easter egg: hovering the mascot sends a UFO down to abduct the yak. */
        .hero-mascot .ufo {
            position: absolute;
            top: 0;
            left: 50%;
            width: 42%;
            height: auto;
            pointer-events: none;
            opacity: 0;
            translate: -50% -650%;
            filter: drop-shadow(0 14px 20px rgba(61, 79, 95, 0.28));
            /* Hover-out: ease-in accel off-screen, opacity fades late so the UFO
               stays visible while flying away. Entrance transition is set on :hover. */
            transition: opacity 0.8s ease 0.55s,
                        translate 1.4s cubic-bezier(0.42, 0, 1, 1);
            z-index: 4;
        }
        .hero-mascot .abduction-beam {
            position: absolute;
            top: -28%;
            left: 50%;
            width: 30%;
            height: 120%;
            pointer-events: none;
            translate: -50% 0;
            transform-origin: top center;
            transform: scaleY(0);
            opacity: 0;
            background: linear-gradient(to bottom,
                rgba(255, 255, 255, 0.95) 0%,
                rgba(255, 251, 230, 0.80) 18%,
                rgba(255, 243, 180, 0.45) 55%,
                rgba(255, 243, 180, 0.15) 85%,
                rgba(255, 243, 180, 0.00) 100%);
            clip-path: polygon(40% 0%, 60% 0%, 100% 100%, 0% 100%);
            filter: blur(2px) drop-shadow(0 0 14px rgba(255, 236, 170, 0.55));
            /* Hover-out: snap off instantly. Hover-in delay + ease is set on :hover. */
            transition: transform 0.12s ease 0s,
                        opacity   0.12s ease 0s;
            z-index: 1;
        }
        .hero-mascot:hover .ufo {
            opacity: 1;
            translate: -50% -118%;
            animation: ufo-wobble 2.6s ease-in-out 1.1s infinite;
            transition: opacity 0.35s ease,
                        translate 1.0s cubic-bezier(0.34, 1.2, 0.64, 1);
        }
        .hero-mascot:hover .abduction-beam {
            opacity: 1;
            transform: scaleY(1);
            transition: transform 0.5s ease 0.55s,
                        opacity   0.5s ease 0.55s;
        }
        .hero-mascot:hover .mascot-img {
            translate: 0 -14px;
            rotate: -2deg;
            scale: 0.92;
        }
        /* Wobble only touches transform + rotate so translate stays owned by the
           CSS transition (otherwise the UFO snaps on unhover). */
        @keyframes ufo-wobble {
            0%, 100% { transform: translateY(0);    rotate: -2.5deg; }
            50%      { transform: translateY(-6px); rotate:  2.5deg; }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-mascot .ufo,
            .hero-mascot .abduction-beam,
            .hero-mascot .mascot-img { transition: none; animation: none; }
        }

        .papercut {
            position: absolute;
            background: #fff;
            box-shadow: 0 6px 14px rgba(61,79,95,0.08), 0 1px 3px rgba(61,79,95,0.05);
            border: 1px solid color-mix(in srgb, var(--yak-tan) 30%, transparent);
        }
        .papercut::after {
            content: '';
            display: block;
            position: absolute;
            top: 24%;
            left: 15%;
            right: 35%;
            height: 2px;
            background: var(--yak-danger);
            border-radius: 2px;
        }
        .papercut-1 {
            top: 6%; left: -30px;
            width: 120px; height: 80px;
            transform: rotate(-8deg);
            border-radius: 3px;
        }
        .papercut-2 {
            top: 46%; right: -24px;
            width: 96px; height: 62px;
            transform: rotate(11deg);
            border-radius: 3px;
        }
        .papercut-2::after { top: 34%; }

        .ribbon {
            margin-top: 60px;
            padding: 22px 30px;
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(30px) saturate(1.4);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-1);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 28px;
            justify-content: space-between;
        }
        .ribbon .label {
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--yak-blue);
        }
        .ribbon .channels {
            display: flex;
            align-items: center;
            gap: 34px;
            flex-wrap: wrap;
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--yak-slate);
        }
        .ribbon .channels span { display: inline-flex; align-items: center; gap: 10px; }
        .ribbon .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--yak-tan);
        }
        .ribbon .d-slack  { background: #611f69; }
        .ribbon .d-linear { background: var(--yak-slate); }
        .ribbon .d-sentry { background: var(--yak-danger); }
        .ribbon .d-github { background: #1a1612; }

        .section-head {
            max-width: 780px;
            margin: 0 auto 60px;
            text-align: center;
        }
        .section-head .eyebrow { margin-bottom: 14px; display: inline-block; }
        .section-head h2 {
            font-size: clamp(40px, 5.5vw, 68px);
            line-height: 1.04;
            letter-spacing: -0.025em;
        }
        .section-head h2 em {
            font-style: italic;
            color: var(--yak-orange);
        }
        .section-head .sub {
            margin-top: 18px;
            font-size: 18px;
            line-height: 1.55;
            color: color-mix(in srgb, var(--yak-slate) 72%, transparent);
            max-width: 620px;
            margin-left: auto;
            margin-right: auto;
        }

        .channel-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }
        .channel-card {
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: var(--radius-card);
            padding: 26px;
            box-shadow: var(--shadow-1);
            backdrop-filter: blur(20px);
            transition: transform .25s ease, box-shadow .25s ease;
        }
        .channel-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-2);
        }
        .channel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .channel-name {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--yak-slate);
        }
        .channel-name .chip {
            width: 26px; height: 26px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
        }
        .chip-slack  { background: #611f69; }
        .chip-linear { background: var(--yak-slate); }
        .chip-sentry { background: var(--yak-danger); }

        .chat {
            font-family: var(--font-mono);
            font-size: 12.5px;
            line-height: 1.6;
            background: var(--yak-cream);
            border-radius: 14px;
            padding: 16px 18px;
            border: 1px solid color-mix(in srgb, var(--yak-tan) 30%, transparent);
            color: var(--yak-slate);
            min-height: 180px;
        }
        .chat .user    { color: var(--yak-blue); font-weight: 600; }
        .chat .yak     { color: var(--yak-orange); font-weight: 600; }
        .chat .mention { color: var(--yak-green); }
        .chat .meta    { color: color-mix(in srgb, var(--yak-slate) 50%, transparent); font-size: 11px; }
        .chat-line + .chat-line { margin-top: 8px; }

        .label-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 6px;
            background: color-mix(in srgb, var(--yak-orange) 15%, transparent);
            color: var(--yak-orange);
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
        }
        .label-tag.green {
            background: color-mix(in srgb, var(--yak-green) 18%, transparent);
            color: var(--yak-green);
        }

        .channel-foot {
            margin-top: 18px;
            font-size: 13.5px;
            color: color-mix(in srgb, var(--yak-slate) 72%, transparent);
        }
        .channel-foot strong {
            font-weight: 600;
            color: var(--yak-slate);
        }

        .pull-row {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 60px;
            align-items: center;
        }
        .pull-row.rev { grid-template-columns: 1fr 1.1fr; }
        .pull-row h3 {
            font-size: clamp(34px, 4.2vw, 54px);
            line-height: 1.06;
            letter-spacing: -0.025em;
            margin-bottom: 18px;
        }
        .pull-row h3 em { font-style: italic; color: var(--yak-orange); }
        .pull-row p {
            font-size: 17px;
            line-height: 1.6;
            color: color-mix(in srgb, var(--yak-slate) 76%, transparent);
            margin-bottom: 18px;
        }

        .bullets {
            list-style: none;
            padding: 0;
            margin: 26px 0 0;
            display: grid;
            gap: 12px;
        }
        .bullets li {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            font-size: 15.5px;
            line-height: 1.5;
            color: var(--yak-slate);
        }
        .bullets li::before {
            content: '';
            flex-shrink: 0;
            display: inline-block;
            width: 8px; height: 8px;
            margin-top: 10px;
            border-radius: 2px;
            background: var(--yak-orange);
            transform: rotate(45deg);
        }

        .autopickup {
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: var(--radius-card);
            padding: 30px;
            box-shadow: var(--shadow-1);
        }
        .autopickup h4 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 20px;
            color: var(--yak-slate);
        }

        .event-row {
            display: grid;
            grid-template-columns: 90px 1fr auto;
            align-items: center;
            gap: 18px;
            padding: 16px 0;
            border-top: 1px dashed color-mix(in srgb, var(--yak-tan) 50%, transparent);
        }
        .event-row:first-of-type { border-top: 0; padding-top: 8px; }
        .event-row .tag {
            font-family: var(--font-mono);
            font-size: 10.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
            text-align: center;
        }
        .tag-sentry { background: color-mix(in srgb, var(--yak-danger) 12%, transparent); color: var(--yak-danger); }
        .tag-flaky  { background: color-mix(in srgb, var(--yak-orange) 14%, transparent); color: var(--yak-orange); }
        .tag-alert  { background: color-mix(in srgb, var(--yak-blue) 14%, transparent); color: var(--yak-blue); }
        .event-body .title {
            font-size: 15.5px;
            color: var(--yak-slate);
            font-weight: 500;
        }
        .event-body .sub {
            font-family: var(--font-mono);
            font-size: 12px;
            color: color-mix(in srgb, var(--yak-slate) 58%, transparent);
            margin-top: 4px;
        }
        .event-row .status {
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            padding: 5px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            background: color-mix(in srgb, var(--yak-green) 14%, transparent);
            color: var(--yak-green);
        }

        .research-card {
            background: #fff;
            border: 1px solid color-mix(in srgb, var(--yak-tan) 40%, transparent);
            border-radius: 18px;
            box-shadow: var(--shadow-2);
            overflow: hidden;
        }
        .research-chrome {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            border-bottom: 1px solid color-mix(in srgb, var(--yak-tan) 30%, transparent);
            background: linear-gradient(to bottom, rgba(232,224,210,0.4), rgba(232,224,210,0.1));
        }
        .research-chrome .dots { display: inline-flex; gap: 6px; }
        .research-chrome .dots span {
            width: 10px; height: 10px; border-radius: 50%;
            background: color-mix(in srgb, var(--yak-tan) 60%, transparent);
        }
        .research-chrome .url {
            margin-left: 14px;
            font-family: var(--font-mono);
            font-size: 11.5px;
            color: color-mix(in srgb, var(--yak-slate) 65%, transparent);
        }
        .research-body { padding: 28px 32px 32px; }
        .research-body .badge {
            display: inline-block;
            font-family: var(--font-mono);
            font-size: 10.5px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--yak-orange);
            background: color-mix(in srgb, var(--yak-orange) 12%, transparent);
            padding: 4px 10px;
            border-radius: 4px;
            margin-bottom: 14px;
        }
        .research-body h5 {
            font-family: var(--font-serif);
            font-size: 26px;
            letter-spacing: -0.015em;
            margin: 0 0 8px;
            color: var(--yak-slate);
        }
        .research-body .summary {
            font-size: 14.5px;
            color: color-mix(in srgb, var(--yak-slate) 70%, transparent);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .chart {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }
        .chart-row {
            display: grid;
            grid-template-columns: 140px 1fr 50px;
            align-items: center;
            gap: 12px;
            font-size: 13px;
        }
        .chart-row .k {
            font-family: var(--font-mono);
            font-size: 11.5px;
            color: var(--yak-slate);
        }
        .chart-row .bar-wrap {
            height: 10px;
            background: var(--yak-cream-dark);
            border-radius: 4px;
            overflow: hidden;
        }
        .chart-row .bar {
            height: 100%;
            background: linear-gradient(to right, var(--yak-orange), var(--yak-orange-warm));
            border-radius: 4px;
        }
        .chart-row .n {
            font-family: var(--font-mono);
            font-size: 11.5px;
            color: var(--yak-blue);
            text-align: right;
        }

        .safety {
            background: linear-gradient(180deg, var(--yak-cream-dark) 0%, var(--yak-cream) 100%);
            position: relative;
        }
        .safety::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(to right, transparent, color-mix(in srgb, var(--yak-tan) 60%, transparent), transparent);
        }
        .safety::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(to right, transparent, color-mix(in srgb, var(--yak-tan) 60%, transparent), transparent);
        }

        .safety-statement {
            max-width: 920px;
            margin: 0 auto;
            text-align: center;
        }
        .safety-statement h2 {
            font-size: clamp(42px, 6vw, 80px);
            line-height: 1.04;
            letter-spacing: -0.03em;
        }
        .safety-statement h2 em { font-style: italic; color: var(--yak-orange); }
        .safety-statement h2 .strike {
            position: relative;
            color: color-mix(in srgb, var(--yak-slate) 45%, transparent);
        }
        .safety-statement h2 .strike::after {
            content: '';
            position: absolute;
            left: -4%;
            right: -4%;
            top: 54%;
            height: 3px;
            background: var(--yak-danger);
            transform: rotate(-3deg);
            border-radius: 3px;
        }

        .flow {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin: 72px auto 0;
            max-width: 1020px;
        }
        .flow-step {
            background: #fff;
            border-radius: 18px;
            border: 1px solid color-mix(in srgb, var(--yak-tan) 35%, transparent);
            padding: 20px 18px;
            position: relative;
            text-align: left;
        }
        .flow-step .num {
            position: absolute;
            top: -14px;
            left: 18px;
            background: var(--yak-slate);
            color: var(--yak-cream);
            font-family: var(--font-serif);
            font-size: 14px;
            line-height: 1;
            padding: 6px 10px;
            border-radius: 8px;
        }
        .flow-step .k {
            font-family: var(--font-mono);
            font-size: 10.5px;
            letter-spacing: 0.1em;
            color: var(--yak-blue);
            text-transform: uppercase;
            margin-top: 6px;
            margin-bottom: 10px;
            display: block;
        }
        .flow-step h6 {
            font-family: var(--font-serif);
            font-size: 18px;
            margin: 0 0 6px;
            color: var(--yak-slate);
            letter-spacing: -0.01em;
        }
        .flow-step p {
            font-size: 13px;
            line-height: 1.5;
            color: color-mix(in srgb, var(--yak-slate) 70%, transparent);
        }
        .flow-step.human {
            background: color-mix(in srgb, var(--yak-orange) 12%, white);
            border-color: color-mix(in srgb, var(--yak-orange) 35%, transparent);
        }
        .flow-step.human .num { background: var(--yak-orange); color: #fff; }

        .guarantees {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-top: 60px;
        }
        .guarantee {
            padding: 22px;
            background: rgba(255,255,255,0.65);
            border: 1px solid color-mix(in srgb, var(--yak-tan) 35%, transparent);
            border-radius: 18px;
        }
        .guarantee .icon {
            display: inline-flex;
            width: 38px; height: 38px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: color-mix(in srgb, var(--yak-green) 16%, transparent);
            color: var(--yak-green);
            margin-bottom: 12px;
        }
        .guarantee h6 {
            font-family: var(--font-serif);
            font-size: 18px;
            margin: 0 0 6px;
            letter-spacing: -0.01em;
            color: var(--yak-slate);
        }
        .guarantee p {
            font-size: 13.5px;
            color: color-mix(in srgb, var(--yak-slate) 72%, transparent);
            line-height: 1.5;
        }

        .proof-row {
            display: grid;
            grid-template-columns: 1fr 1.05fr;
            gap: 60px;
            align-items: center;
        }
        .proof-frame {
            position: relative;
        }
        .proof-screenshot {
            background: #fff;
            border-radius: 18px;
            border: 1px solid color-mix(in srgb, var(--yak-tan) 40%, transparent);
            box-shadow: var(--shadow-2);
            padding: 6px;
            position: relative;
            z-index: 2;
        }
        .proof-screenshot .stripe {
            display: flex;
            gap: 6px;
            padding: 10px 12px;
        }
        .proof-screenshot .stripe span {
            width: 9px; height: 9px; border-radius: 50%;
            background: color-mix(in srgb, var(--yak-tan) 55%, transparent);
        }
        .proof-screenshot .canvas {
            border-radius: 14px;
            aspect-ratio: 16 / 10;
            background:
                linear-gradient(135deg, color-mix(in srgb, var(--yak-blue-light) 55%, white), color-mix(in srgb, var(--yak-green) 25%, white)),
                var(--yak-cream-dark);
            position: relative;
            overflow: hidden;
        }
        .proof-screenshot .canvas::before {
            content: '';
            position: absolute;
            inset: 20px 24px auto 24px;
            height: 34px;
            background: rgba(255,255,255,0.9);
            border-radius: 10px;
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--yak-tan) 45%, transparent);
        }
        .proof-screenshot .canvas::after {
            content: '';
            position: absolute;
            left: 24px; right: 24px;
            bottom: 22px;
            top: 74px;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.9) 0, rgba(255,255,255,0.9) 30%, transparent 30%, transparent 100%),
                linear-gradient(180deg, rgba(255,255,255,0.9) 0, rgba(255,255,255,0.9) 60%, transparent 60%, transparent 65%, rgba(255,255,255,0.9) 65%, rgba(255,255,255,0.9) 100%);
            border-radius: 8px;
            backdrop-filter: blur(6px);
            background-color: rgba(255,255,255,0.25);
        }

        .proof-video {
            position: absolute;
            bottom: -44px;
            right: -36px;
            width: 50%;
            background: #1a1612;
            border-radius: 16px;
            border: 6px solid #fff;
            box-shadow: var(--shadow-2);
            padding-bottom: 32%;
            z-index: 3;
            overflow: hidden;
        }
        .proof-video::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 35% 40%, rgba(196, 116, 74, 0.6), transparent 60%),
                radial-gradient(circle at 75% 70%, rgba(122, 140, 94, 0.5), transparent 55%),
                #2a2420;
        }
        .proof-video::after {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            width: 44px; height: 44px;
            transform: translate(-50%, -50%);
            background: rgba(255,255,255,0.95);
            border-radius: 50%;
            clip-path: polygon(35% 25%, 35% 75%, 78% 50%);
        }
        .proof-video .rec {
            position: absolute;
            top: 10px; left: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: var(--font-mono);
            font-size: 10px;
            color: #fff;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            z-index: 4;
        }
        .proof-video .rec::before {
            content: '';
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--yak-danger);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--yak-danger) 30%, transparent);
        }

        .proof-tag {
            position: absolute;
            top: -18px;
            left: 20px;
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            color: var(--yak-green);
            background: #fff;
            padding: 5px 11px;
            border-radius: 999px;
            border: 1px solid color-mix(in srgb, var(--yak-green) 30%, transparent);
            z-index: 5;
        }
        .proof-tag::before {
            content: '✓';
            margin-right: 6px;
            color: var(--yak-green);
        }

        .setup-row {
            display: grid;
            grid-template-columns: 1fr 1.1fr;
            gap: 60px;
            align-items: center;
        }
        .terminal {
            background: #1a1612;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: var(--shadow-2);
            overflow: hidden;
            font-family: var(--font-mono);
            font-size: 12.5px;
            color: #e8e0d2;
        }
        .terminal-head {
            padding: 12px 14px;
            background: #24201c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .terminal-head .dots { display: inline-flex; gap: 6px; }
        .terminal-head .dots span {
            width: 10px; height: 10px; border-radius: 50%;
            background: #3a342e;
        }
        .terminal-head .title {
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.06em;
            color: #9a938a;
            text-transform: uppercase;
            margin-left: 4px;
        }
        .terminal-body {
            padding: 22px 24px 24px;
            line-height: 1.75;
        }
        .term-line { display: block; }
        .term-line .pfx { color: var(--yak-green-muted); }
        .term-line .cmt { color: #6f6a62; }
        .term-line .tag-ok { color: var(--yak-green-muted); font-weight: 600; }
        .term-line .tag-run { color: var(--yak-orange-warm); font-weight: 600; }
        .term-line .b { color: var(--yak-blue-light); }
        .term-line .o { color: var(--yak-orange-warm); }
        .term-line .dim { color: #8b847b; }

        .closing {
            text-align: center;
            padding: 140px 0 130px;
        }
        .closing h2 {
            font-size: clamp(46px, 7vw, 108px);
            line-height: 0.92;
            letter-spacing: -0.035em;
            margin-bottom: 32px;
        }
        .closing h2 em { font-style: italic; color: var(--yak-orange); }
        .closing .sub {
            font-size: 18px;
            color: color-mix(in srgb, var(--yak-slate) 72%, transparent);
            max-width: 560px;
            margin: 0 auto 36px;
            line-height: 1.55;
        }

        footer {
            padding: 36px 0 48px;
            border-top: 1px solid color-mix(in srgb, var(--yak-tan) 45%, transparent);
            font-size: 13px;
            color: color-mix(in srgb, var(--yak-slate) 65%, transparent);
        }
        footer .row {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        footer .mark {
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--yak-slate);
            letter-spacing: -0.04em;
            text-decoration: none;
        }
        footer .mark .a { font-style: italic; color: var(--yak-orange); }
        footer .tagline {
            font-family: var(--font-serif);
            font-style: italic;
            font-size: 16px;
            color: color-mix(in srgb, var(--yak-slate) 70%, transparent);
            letter-spacing: -0.01em;
        }

        .reveal {
            animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .reveal.d1 { animation-delay: 0.10s; }
        .reveal.d2 { animation-delay: 0.20s; }
        .reveal.d3 { animation-delay: 0.30s; }
        .reveal.d4 { animation-delay: 0.40s; }
        .reveal.d5 { animation-delay: 0.55s; }
        .reveal.d6 { animation-delay: 0.70s; }

        @keyframes rise {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .float {
            animation: float 8s ease-in-out infinite;
        }
        @keyframes float {
            0%,100% { transform: translateY(0); }
            50%     { transform: translateY(-10px); }
        }

        @media (max-width: 960px) {
            section { padding: 78px 0; }
            .hero { padding: 48px 0 40px; }
            .hero-grid { grid-template-columns: 1fr; gap: 20px; }
            .hero-mascot { order: -1; max-width: 360px; margin: 0 auto; }
            .pull-row, .pull-row.rev { grid-template-columns: 1fr; gap: 36px; }
            .channel-grid { grid-template-columns: 1fr; }
            .flow { grid-template-columns: repeat(2, 1fr); }
            .guarantees { grid-template-columns: repeat(2, 1fr); }
            .proof-row, .setup-row { grid-template-columns: 1fr; gap: 50px; }
            .proof-video { bottom: -30px; right: -16px; }
        }
        @media (max-width: 520px) {
            .flow { grid-template-columns: 1fr; }
            .guarantees { grid-template-columns: 1fr; }
            .ribbon .channels { gap: 18px; font-size: 18px; }
        }
    </style>
</head>
<body>

<header>
    <div class="topbar">
        <a href="{{ route('home') }}" class="mark" aria-label="Yak">Y<span class="a">a</span>k</a>
        <a href="{{ route('login') }}" class="btn btn-ghost btn-compact">
            Sign in
            <svg class="arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </a>
    </div>
</header>

<section class="hero">
    <div class="container">
        <div class="hero-grid">
            <div>
                <div class="eyebrow reveal">An autonomous coding agent for papercuts</div>

                <h1 class="wordmark reveal d1">Y<span class="a">a</span>k<span style="color:var(--yak-orange)">.</span></h1>

                <p class="lede reveal d2">
                    The small stuff — <em>flaky tests, Sentry bugs,
                    copy tweaks, broken CSV exports</em> — handled while
                    you work on what matters.
                </p>

                <p class="subline reveal d3">
                    Yak watches Slack, Linear, Sentry, and our CI.
                    When something small breaks, it opens a branch, writes
                    the fix, verifies it in CI, and hands you a pull request
                    with screenshots and a video walkthrough. Got a question
                    about the codebase instead? You'll get an answer back,
                    not a PR.
                </p>

                <div class="hero-cta reveal d4">
                    <a href="{{ route('login') }}" class="btn btn-primary">
                        Sign in to the dashboard
                        <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </a>
                    <span class="tagline">Google SSO · team access only</span>
                </div>
            </div>

            <div class="hero-mascot reveal d3">
                <div class="papercut papercut-1"></div>
                <div class="papercut papercut-2"></div>
                <div class="abduction-beam" aria-hidden="true"></div>
                <img src="{{ asset('ufo.png') }}" alt="" class="ufo" aria-hidden="true">
                <img src="{{ asset('mascot.png') }}" alt="Yak mascot" class="mascot-img float">
            </div>
        </div>

        <div class="ribbon reveal d5">
            <span class="label">Meets you where you are</span>
            <div class="channels">
                <span><span class="dot d-slack"></span>Slack</span>
                <span><span class="dot d-linear"></span>Linear</span>
                <span><span class="dot d-sentry"></span>Sentry</span>
                <span><span class="dot d-github"></span>GitHub</span>
                <span style="color: color-mix(in srgb, var(--yak-slate) 55%, transparent); font-style: italic;">+ flaky tests, research mode</span>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">Input channels</span>
            <h2>Mention it. <em>Assign it.</em> Forget it.</h2>
            <p class="sub">
                Yak plugs into the tools the team already lives in. Tag a Slack
                thread, assign a Linear issue, let a Sentry alert fire — the
                routing layer takes it from there.
            </p>
        </div>

        <div class="channel-grid">
            <div class="channel-card">
                <div class="channel-header">
                    <span class="channel-name">
                        <span class="chip chip-slack">#</span>
                        Slack
                    </span>
                    <span class="caption">@yak</span>
                </div>
                <div class="chat">
                    <div class="chat-line"><span class="meta">#engineering · 10:42</span></div>
                    <div class="chat-line"><span class="user">maria</span> <span class="mention">@yak</span> the CSV export is dropping the trailing zip digit again 🙃</div>
                    <div class="chat-line"><span class="yak">yak</span> On it. I see three ways this could read — picking the right one first…</div>
                    <div class="chat-line"><span class="yak">yak</span> <span style="color: var(--yak-green)">✓ PR #284 opened</span> — full regression test + screenshot attached.</div>
                </div>
                <p class="channel-foot">
                    <strong>Ambiguity-aware.</strong> If the ask could be read
                    two ways, Yak replies with grounded options before
                    writing a single line of code.
                </p>
            </div>

            <div class="channel-card">
                <div class="channel-header">
                    <span class="channel-name">
                        <span class="chip chip-linear">L</span>
                        Linear
                    </span>
                    <span class="caption">assign · @yak</span>
                </div>
                <div class="chat">
                    <div class="chat-line" style="color: var(--yak-slate);"><strong>GEO-1284</strong> · Dark mode contrast on pricing cards</div>
                    <div class="chat-line" style="margin: 10px 0;">
                        <span class="label-tag">assigned · Yak</span>
                        <span class="label-tag green">in‑review</span>
                    </div>
                    <div class="chat-line"><span class="yak">yak</span> → acknowledged in the agent session</div>
                    <div class="chat-line"><span class="yak">yak</span> → In&nbsp;Review · <span style="color: var(--yak-green);">PR ready for human review</span></div>
                    <div class="chat-line"><span class="meta">Runs via Linear's Agents API · no seat required</span></div>
                </div>
                <p class="channel-foot">
                    <strong>Assign-driven.</strong> Assign any issue to the
                    <span class="mono" style="color:var(--yak-orange); font-size: 13px;">Yak</span>
                    agent — fix or question, Yak classifies it upfront.
                    A <span class="mono" style="color:var(--yak-green); font-size: 13px;">research</span>
                    label is optional, just a shortcut past the classifier.
                </p>
            </div>

            <div class="channel-card">
                <div class="channel-header">
                    <span class="channel-name">
                        <span class="chip chip-sentry">!</span>
                        Sentry
                    </span>
                    <span class="caption">auto-triage</span>
                </div>
                <div class="chat">
                    <div class="chat-line" style="color: var(--yak-danger); font-weight: 600;">TypeError: Cannot read 'accuracy' of undefined</div>
                    <div class="chat-line"><span class="meta">events: 43 · users: 12 · first seen 2h ago</span></div>
                    <div class="chat-line"><span class="yak">yak</span> filter: <span style="color: var(--yak-green);">actionable ✓</span> · not CSP · not transient infra</div>
                    <div class="chat-line"><span class="yak">yak</span> pulling breadcrumbs via Sentry MCP…</div>
                    <div class="chat-line"><span class="yak">yak</span> <span style="color:var(--yak-green)">✓ fix + regression test pushed</span></div>
                </div>
                <p class="channel-foot">
                    <strong>Filters the noise.</strong> CSP violations, redis
                    blips, and one-off user errors get dropped — only real,
                    actionable issues with enough signal get a task.
                </p>
            </div>
        </div>
    </div>
</section>

<section style="background: linear-gradient(180deg, var(--yak-cream) 0%, var(--yak-cream-dark) 100%); padding-top: 90px; padding-bottom: 120px;">
    <div class="container">
        <div class="pull-row">
            <div>
                <span class="eyebrow">Goes looking for trouble</span>
                <h3 style="margin-top:14px;">Picks up <em>Sentry</em> issues and <em>flaky tests</em> on its own.</h3>
                <p>
                    Point Yak at your Sentry alert rule and your CI log. It
                    wakes up when an actionable issue crosses the threshold or
                    when a test starts failing on <span class="mono" style="font-size: 15px; color: var(--yak-slate); background: var(--yak-cream); padding: 2px 6px; border-radius: 4px;">main</span>.
                    Then it reads the stacktrace, forms a hypothesis, and
                    writes a fix plus a regression test.
                </p>
                <ul class="bullets">
                    <li>Aggressive pre-filters: CSP, transient infra, low-signal events dropped before they cost a dime.</li>
                    <li>Flaky test triage: <em>real bug or racey test?</em> — Yak reports back honestly instead of papering over it.</li>
                    <li>Per-repo budget and daily cost caps keep alert storms from wreaking havoc.</li>
                </ul>
            </div>

            <div>
                <div class="autopickup">
                    <div style="display:flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <h4>Recent auto-pickups</h4>
                        <span class="caption">last 24 h</span>
                    </div>

                    <div class="event-row">
                        <span class="tag tag-sentry">Sentry</span>
                        <div class="event-body">
                            <div class="title">TypeError in <span class="mono" style="font-size: 13px;">GeocodeController@show</span></div>
                            <div class="sub">events 43 · users 12 · sentry-priority: medium</div>
                        </div>
                        <span class="status">PR opened</span>
                    </div>

                    <div class="event-row">
                        <span class="tag tag-flaky">Flaky</span>
                        <div class="event-body">
                            <div class="title">BatchUploadTest::<span class="mono" style="font-size: 13px;">it_handles_merged_headers</span></div>
                            <div class="sub">fails 3/10 builds · racy fixture timestamp</div>
                        </div>
                        <span class="status">fixed</span>
                    </div>

                    <div class="event-row">
                        <span class="tag tag-alert">Linear</span>
                        <div class="event-body">
                            <div class="title">Dark-mode contrast on pricing cards</div>
                            <div class="sub">GEO-1284 · assigned to Yak</div>
                        </div>
                        <span class="status">in review</span>
                    </div>

                    <div class="event-row">
                        <span class="tag tag-sentry">Sentry</span>
                        <div class="event-body">
                            <div class="title">Null dereference in <span class="mono" style="font-size: 13px;">AccuracyScorer</span></div>
                            <div class="sub">events 18 · seer: actionable ✓</div>
                        </div>
                        <span class="status">PR opened</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="container">
        <div class="pull-row rev">
            <div class="research-card">
                <div class="research-chrome">
                    <div class="dots"><span></span><span></span><span></span></div>
                    <div class="url">.yak-artifacts/research.html</div>
                </div>
                <div class="research-body">
                    <span class="badge">Research findings</span>
                    <h5>Deprecated <span style="font-style:italic;">accuracy_type</span> usage across the API.</h5>
                    <p class="summary">
                        7 endpoints still read <span class="mono" style="font-size:12.5px; color: var(--yak-orange);">accuracy_type</span>
                        directly. Two customer-facing, five internal. Estimated
                        migration: 2 PRs, ~180 LOC. No blocking callers.
                    </p>

                    <div class="chart">
                        <div class="chart-row">
                            <span class="k">GeocodeCtrl</span>
                            <div class="bar-wrap"><div class="bar" style="width: 92%;"></div></div>
                            <span class="n">42</span>
                        </div>
                        <div class="chart-row">
                            <span class="k">BatchCtrl</span>
                            <div class="bar-wrap"><div class="bar" style="width: 64%;"></div></div>
                            <span class="n">28</span>
                        </div>
                        <div class="chart-row">
                            <span class="k">ReverseCtrl</span>
                            <div class="bar-wrap"><div class="bar" style="width: 48%;"></div></div>
                            <span class="n">19</span>
                        </div>
                        <div class="chart-row">
                            <span class="k">LookupSvc</span>
                            <div class="bar-wrap"><div class="bar" style="width: 38%;"></div></div>
                            <span class="n">14</span>
                        </div>
                        <div class="chart-row">
                            <span class="k">ExportSvc</span>
                            <div class="bar-wrap"><div class="bar" style="width: 22%;"></div></div>
                            <span class="n">7</span>
                        </div>
                    </div>

                    <div style="margin-top: 22px; padding-top: 18px; border-top: 1px dashed color-mix(in srgb, var(--yak-tan) 55%, transparent); display:flex; gap: 22px; font-family: var(--font-mono); font-size: 12px; color: color-mix(in srgb, var(--yak-slate) 65%, transparent);">
                        <span>→ file refs w/ line numbers</span>
                        <span>→ risk: low</span>
                        <span>→ effort: 2 PRs</span>
                    </div>
                </div>
            </div>

            <div>
                <span class="eyebrow">Ask a question</span>
                <h3 style="margin-top:14px;">Not every ask is a <em>fix.</em> Sometimes you just want an <em>answer.</em></h3>
                <p>
                    <em style="color: var(--yak-orange);">"When is the welcome email triggered?"</em>
                    <em style="color: var(--yak-orange);">"How bad would this refactor be?"</em>
                    Just ask. A lightweight classifier decides up front
                    whether your request is a fix or a research question,
                    so there are no magic prefixes to remember.
                </p>
                <ul class="bullets">
                    <li>Quick factual questions come back as a conversational answer in the same thread — no branch, no CI, no PR.</li>
                    <li>Bigger investigations produce a self-contained HTML findings page with file references, charts, risks, and an effort estimate.</li>
                    <li>Explicit overrides still work: a <span class="mono" style="color: var(--yak-orange); font-size: 14.5px;">research</span> label on Linear or a <span class="mono" style="color: var(--yak-orange); font-size: 14.5px;">research:</span> prefix in Slack skips the classifier.</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="safety">
    <div class="container">
        <div class="safety-statement">
            <span class="eyebrow">The safety model</span>
            <h2 style="margin-top: 14px;">
                Yak opens pull requests.<br>
                <em>Humans</em> merge them.<br>
                <span class="strike">No auto-merge.</span> No exceptions.
            </h2>
        </div>

        <div class="flow">
            <div class="flow-step">
                <span class="num">1</span>
                <span class="k">Yak</span>
                <h6>Writes&nbsp;the&nbsp;fix</h6>
                <p>Branch, commit, local tests — every run in a fresh sandbox. Never pushes to <span class="mono" style="font-size: 12px;">main</span>.</p>
            </div>
            <div class="flow-step">
                <span class="num">2</span>
                <span class="k">CI</span>
                <h6>Proves&nbsp;it</h6>
                <p>Full test suite runs on real CI. If it fails, Yak retries once. If that fails, a human takes over.</p>
            </div>
            <div class="flow-step">
                <span class="num">3</span>
                <span class="k">Yak</span>
                <h6>Opens&nbsp;the&nbsp;PR</h6>
                <p>Screenshots, video, summary, cost, session id. Large diffs get a <span class="mono" style="font-size:11.5px;">yak-large-change</span> label.</p>
            </div>
            <div class="flow-step human">
                <span class="num">4</span>
                <span class="k">You</span>
                <h6>Reviews&nbsp;&amp;&nbsp;iterates</h6>
                <p>Push follow-up commits, request changes, or take over entirely. Yak's branch is just a starting point.</p>
            </div>
            <div class="flow-step human">
                <span class="num">5</span>
                <span class="k">You</span>
                <h6>Merges</h6>
                <p>Always. The Yak GitHub App has no merge authority and is never on your bypass list by design.</p>
            </div>
        </div>

        <div class="guarantees">
            <div class="guarantee">
                <div class="icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.6 9.3 8 11 4.4-1.7 8-6 8-11V5l-8-3Z"/></svg>
                </div>
                <h6>No merge authority</h6>
                <p>The Yak GitHub App can push branches and open PRs — nothing more. Branch protection stays on.</p>
            </div>
            <div class="guarantee">
                <div class="icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 10h18"/><path d="M9 15l2 2 4-4"/></svg>
                </div>
                <h6>One sandbox per task</h6>
                <p>Every run lives in its own Incus container, cloned from a ZFS snapshot. Firewalled off from Yak itself, its database, and every other task.</p>
            </div>
            <div class="guarantee">
                <div class="icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </div>
                <h6>Bounded retries</h6>
                <p>At most one retry on CI failure. Two strikes and a human picks up — no endless flailing.</p>
            </div>
            <div class="guarantee">
                <div class="icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="M12 18v4"/><path d="m4.93 4.93 2.83 2.83"/><path d="m16.24 16.24 2.83 2.83"/><path d="M2 12h4"/><path d="M18 12h4"/><path d="m4.93 19.07 2.83-2.83"/><path d="m16.24 7.76 2.83-2.83"/></svg>
                </div>
                <h6>Per-task + daily budgets</h6>
                <p>Every run is capped. The daily routing budget is enforced by job middleware before a single token is spent.</p>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="container">
        <div class="proof-row">
            <div class="proof-frame">
                <span class="proof-tag">Visual capture: done</span>
                <div class="proof-screenshot">
                    <div class="stripe"><span></span><span></span><span></span></div>
                    <div class="canvas"></div>
                </div>
                <div class="proof-video">
                    <span class="rec">REC · 00:12</span>
                </div>
            </div>

            <div>
                <span class="eyebrow">Proof of work</span>
                <h3 style="margin-top:14px;">Every UI change comes with a <em>screenshot</em> and a <em>video walkthrough.</em></h3>
                <p>
                    When Yak touches the frontend, it spins up the dev
                    server, logs in as a seeded test user, drives the
                    affected page through the exact flow it just changed,
                    and attaches the recordings to the PR.
                </p>
                <ul class="bullets">
                    <li>Real Chromium navigation, authenticated session, video recorded end-to-end.</li>
                    <li>Partial captures when something blocks the full flow — never a silent skip.</li>
                    <li>Every result ends with an explicit <span class="mono" style="font-size: 13px; color: var(--yak-green);">Visual capture: done | partial | skipped</span> status line.</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="container">
        <div class="setup-row">
            <div>
                <span class="eyebrow">Add a repo</span>
                <h3 style="margin-top:14px;">Dev environment, <em>frozen on a snapshot.</em></h3>
                <p>
                    Paste the clone URL. Yak dispatches a one-time setup
                    task inside a fresh Incus container — docker-compose
                    up, dependencies, migrations, test suite — then
                    freezes the result as a <strong>ZFS copy-on-write
                    snapshot</strong>. Every future task on this repo
                    clones that snapshot in about two seconds.
                </p>
                <ul class="bullets">
                    <li>Setup runs once. After that, tasks start from a warm, verified template.</li>
                    <li>Sandboxes are destroyed at the end of every task — nothing leaks between runs.</li>
                    <li>Up to four tasks run in parallel, each in its own container with its own network.</li>
                    <li>New deps broke the environment? <strong>Re-run setup</strong> and Yak builds a fresh snapshot.</li>
                </ul>
            </div>

            <div class="terminal">
                <div class="terminal-head">
                    <div class="dots"><span></span><span></span><span></span></div>
                    <div class="title">yak · setup task — api</div>
                </div>
                <div class="terminal-body">
                    <span class="term-line"><span class="cmt"># One-time setup inside a fresh Incus container</span></span>
                    <span class="term-line"><span class="pfx">›</span> incus launch <span class="b">yak-base</span> <span class="o">yak-setup-api</span> <span class="dim">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span> <span class="tag-ok">ok</span></span>
                    <span class="term-line"><span class="pfx">›</span> reading <span class="b">README.md</span>, <span class="b">CLAUDE.md</span>, <span class="b">docker-compose.yml</span></span>
                    <span class="term-line"><span class="pfx">›</span> docker-compose up -d <span class="dim">&nbsp;&nbsp;&nbsp;mysql · redis · meilisearch</span> <span class="tag-ok">ok</span></span>
                    <span class="term-line"><span class="pfx">›</span> composer install &amp;&amp; npm ci &amp;&amp; npm run build <span class="dim">&nbsp;&nbsp;</span> <span class="tag-ok">ok</span></span>
                    <span class="term-line"><span class="pfx">›</span> php artisan migrate --seed <span class="dim">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span> <span class="tag-ok">ok</span></span>
                    <span class="term-line"><span class="pfx">›</span> pest --compact <span class="dim">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span> <span class="tag-ok">432 passed</span></span>
                    <span class="term-line"><span class="pfx">›</span> <span class="cmt"># Promote to a ZFS snapshot</span></span>
                    <span class="term-line"><span class="pfx">›</span> incus snapshot create yak-setup-api <span class="b">ready</span> <span class="dim">&nbsp;</span> <span class="tag-ok">ok</span></span>
                    <span class="term-line"><span class="pfx">›</span> status: <span class="o">setup → ready</span></span>
                    <br>
                    <span class="term-line"><span class="cmt"># Future tasks clone from snapshot → live in ~2s</span></span>
                    <span class="term-line"><span class="pfx">›</span> incus copy yak-tpl-api/ready <span class="o">task-42</span> <span class="dim">&nbsp;&nbsp;&nbsp;&nbsp;</span> <span class="tag-ok">1.8s</span></span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="closing">
    <div class="container">
        <div class="divider" style="margin-bottom: 36px;">
            <span class="line"></span>
            <svg class="star" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            <span class="line"></span>
        </div>

        <h2 class="wordmark">
            Let Yak <em style="font-style:italic; color: var(--yak-orange);">do</em><br>
            the <em style="font-style: italic;">boring</em> bit.
        </h2>

        <p class="sub" style="margin-top: 28px;">
            Papercuts, handled. Sign in to watch tasks in flight,
            read Claude sessions, inspect sandbox artifacts, and
            tweak the prompts Yak runs on your behalf.
        </p>

        <div style="display:flex; gap: 14px; justify-content: center; flex-wrap: wrap;">
            <a href="{{ route('login') }}" class="btn btn-primary">
                Sign in to the dashboard
                <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
        </div>
    </div>
</section>

<footer>
    <div class="row">
        <a href="{{ route('home') }}" class="mark">Y<span class="a">a</span>k</a>
        <span class="tagline">Papercuts, handled.</span>
    </div>
</footer>

</body>
</html>
