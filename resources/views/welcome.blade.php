<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yak: start it on your laptop, beam it up</title>
    <meta name="description" content="Yak is an open source coding agent that runs Claude Code in remote sandboxes. Hand it tasks from Slack, Linear, Sentry, GitHub, or email, or teleport your own session from your terminal. It opens pull requests for you to review.">

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
    /* Layout: mascot-led hero, then one column of alternating copy and product mocks
       on the dashboard's cool neutrals. Hovering the mascot calls the UFO. */
    :root {
        --bg: #fbfbfc;
        --panel: #ffffff;
        --panel-2: #eef0f2;
        --border: #e0e1e6;
        --text: #282a30;
        --text-2: #62666d;
        --brand: #4a6580;        /* the dashboard's slate: identity */
        --brand-soft: rgba(74, 101, 128, 0.10);
        --accent: #b45c35;       /* rust: actions and the beam */
        --accent-soft: rgba(180, 92, 53, 0.09);
        --accent-ink: #ffffff;
        --warm: #c4744a;         /* the mascot's orange: wordmark and emphasis only */
        --ok: #3e8e38;
        --term-bg: #17181c;
        --term-text: #d9dbe1;
        --term-dim: #8a8f99;
        --band-bg: #2c3a47;
        --band-text: #eef1f4;
        --shadow: 0 1px 2px rgba(40, 42, 48, 0.05), 0 12px 32px rgba(40, 42, 48, 0.08);

        --font-display: 'Instrument Serif', 'Iowan Old Style', Georgia, serif;
        --font-sans: 'IBM Plex Sans', ui-sans-serif, -apple-system, 'Segoe UI', sans-serif;
        --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0f1011; --panel: #171819; --panel-2: #232427; --border: #303137;
            --text: #e6e7ea; --text-2: #9499a1;
            --brand: #7f9cb4; --brand-soft: rgba(127, 156, 180, 0.16);
            --accent: #d58f71; --accent-soft: rgba(213, 143, 113, 0.16); --accent-ink: #101014;
            --warm: #d4915e; --ok: #6fbe68;
            --term-bg: #08090a; --term-text: #d9dbe1; --term-dim: #7b808a;
            --band-bg: #171d24; --band-text: #e6e7ea;
            --shadow: 0 1px 2px rgba(0, 0, 0, 0.4), 0 12px 32px rgba(0, 0, 0, 0.45);
            color-scheme: dark;
        }
    }

    * { box-sizing: border-box; }
    img { max-width: 100%; }
    body { margin: 0; overflow-x: hidden; background: var(--bg); color: var(--text); font-family: var(--font-sans); font-size: 17px; line-height: 1.55; -webkit-font-smoothing: antialiased; }
    .wrap { max-width: 1120px; margin: 0 auto; padding-left: max(20px, env(safe-area-inset-left, 0px)); padding-right: max(20px, env(safe-area-inset-right, 0px)); }
    h1, h2, h3 { font-family: var(--font-display); font-weight: 400; letter-spacing: -0.015em; margin: 0; text-wrap: balance; }
    h1 { font-size: clamp(44px, 6.4vw, 78px); line-height: 1.02; }
    h2 { font-size: clamp(34px, 4.4vw, 52px); line-height: 1.06; }
    h3 { font-size: 27px; line-height: 1.15; }
    h1 em, h2 em, h3 em { color: var(--warm); }
    p { margin: 0; }
    code, .mono { font-family: var(--font-mono); font-size: 0.86em; }
    p code { background: var(--panel-2); padding: 2px 6px; border-radius: 5px; }
    a { color: inherit; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .eyebrow { font-family: var(--font-mono); font-size: 12.5px; letter-spacing: 0.09em; text-transform: uppercase; color: var(--brand); }
    .pill { display: inline-flex; align-items: center; gap: 8px; padding: 5px 12px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-family: var(--font-mono); font-size: 12.5px; letter-spacing: 0.04em; }
    .pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--accent); }

    /* nav */
    .nav { display: flex; align-items: center; justify-content: space-between; padding-block: 22px; }
    .wordmark { font-family: var(--font-display); font-size: 34px; line-height: 1; text-decoration: none; }
    .wordmark i, .wordmark b { color: var(--warm); font-weight: 400; }
    .nav-links { display: flex; align-items: center; gap: 22px; font-size: 15px; color: var(--text-2); }
    .nav-links a { text-decoration: none; }
    .nav-links a:hover { color: var(--text); }

    .btn { display: inline-flex; align-items: center; gap: 9px; padding: 12px 20px; border-radius: 8px; font: 500 16px var(--font-sans); text-decoration: none; border: 1px solid transparent; cursor: pointer; }
    .btn-primary { background: var(--accent); color: var(--accent-ink); }
    .btn-primary:hover { filter: brightness(1.1); }
    .btn-quiet { border-color: var(--border); background: var(--panel); color: var(--text); padding: 8px 14px; font-size: 14.5px; }

    /* hero */
    .hero { padding-block: 36px 72px; }
    .hero-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr); gap: 48px; align-items: center; }
    .hero-copy { display: flex; flex-direction: column; gap: 22px; align-items: flex-start; }
    .lede { font-size: 20px; color: var(--text); max-width: 34em; }
    .sub { color: var(--text-2); max-width: 36em; }
    .cta-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: stretch; margin-top: 6px; max-width: 100%; }
    .install { display: inline-flex; align-items: center; gap: 12px; min-width: 0; max-width: 100%; padding: 6px 6px 6px 14px; border-radius: 8px; background: var(--term-bg); color: var(--term-text); font-family: var(--font-mono); font-size: 13.5px; }
    .install span { overflow-x: auto; white-space: nowrap; min-width: 0; }
    .install .dollar { color: var(--term-dim); }
    .install button { flex: none; border: 0; border-radius: 6px; padding: 7px 11px; background: rgba(255, 255, 255, 0.12); color: var(--term-text); font: 500 12.5px var(--font-sans); cursor: pointer; }
    .install button:hover { background: rgba(255, 255, 255, 0.2); }

    /* hero scene. The UFO beams the yak up once on load; hovering the mascot replays it. */
    .scene { position: relative; width: 100%; max-width: 460px; justify-self: center; }
    .scene::before { content: ''; position: absolute; inset: -6%; background: radial-gradient(ellipse at center, var(--brand-soft), transparent 66%); }
    .scene .mascot { position: relative; z-index: 2; width: 100%; display: block; filter: drop-shadow(0 26px 34px rgba(40, 42, 48, 0.2)); animation: float 6s ease-in-out infinite; transition: translate 0.9s cubic-bezier(0.22, 1, 0.36, 1), rotate 0.9s cubic-bezier(0.22, 1, 0.36, 1), scale 0.8s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.4s ease; }
    .scene .ufo { position: absolute; top: 0; left: 50%; width: 42%; z-index: 4; pointer-events: none; opacity: 0; translate: -50% -650%; filter: drop-shadow(0 14px 20px rgba(40, 42, 48, 0.3)); transition: opacity 0.8s ease 0.55s, translate 1.4s cubic-bezier(0.42, 0, 1, 1); }
    .scene .beam { position: absolute; top: -6%; left: 50%; width: 30%; height: 120%; z-index: 1; pointer-events: none; translate: -50% 0; transform-origin: top center; transform: scaleY(0); opacity: 0; clip-path: polygon(40% 0, 60% 0, 100% 100%, 0 100%); background: linear-gradient(to bottom, color-mix(in srgb, var(--accent) 70%, transparent), color-mix(in srgb, var(--accent) 30%, transparent) 55%, transparent); filter: blur(2px); transition: transform 0.12s ease, opacity 0.12s ease; }
    .scene:is(:hover, .beaming) .ufo { opacity: 1; translate: -50% -118%; animation: wobble 2.6s ease-in-out 1.1s infinite; transition: opacity 0.35s ease, translate 1s cubic-bezier(0.34, 1.2, 0.64, 1); }
    .scene:is(:hover, .beaming) .beam { opacity: 1; transform: scaleY(1); transition: transform 0.5s ease 0.55s, opacity 0.5s ease 0.55s; }
    .scene:is(:hover, .beaming) .mascot { translate: 0 -56%; rotate: -2deg; scale: 0.1; opacity: 0; transition: translate 1.8s cubic-bezier(0.42, 0, 0.58, 1) 2s, rotate 1.4s cubic-bezier(0.42, 0, 0.58, 1) 2s, scale 1.5s cubic-bezier(0.42, 0, 0.58, 1) 1s, opacity 0.6s ease-in 3s; }
    @keyframes wobble { 0%, 100% { transform: translateY(0); rotate: 0deg; } 25% { transform: translateY(-5px); rotate: 3deg; } 75% { transform: translateY(-4px); rotate: -3deg; } }
    @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
    @media (prefers-reduced-motion: reduce) { .scene .ufo, .scene .beam, .scene .mascot { animation: none; transition: none; } }

    /* channel ribbon */
    .ribbon { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 28px; margin-top: 56px; padding-block: 18px; border-block: 1px solid var(--border); }
    .channels { display: flex; flex-wrap: wrap; gap: 8px 24px; font-family: var(--font-display); font-size: 23px; }
    .channels span { display: inline-flex; align-items: center; gap: 9px; }
    .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--brand); }
    .dot.slack { background: #8a3f93; } .dot.sentry { background: #c25a55; } .dot.email { background: var(--warm); } .dot.ci { background: var(--ok); } .dot.github { background: var(--text); } .dot.linear { background: var(--accent); }

    section { padding-block: 84px; }
    .head { display: flex; flex-direction: column; gap: 14px; max-width: 760px; margin-bottom: 44px; }
    .head p { color: var(--text-2); font-size: 19px; }

    /* terminals */
    .term { border-radius: 10px; background: var(--term-bg); color: var(--term-text); box-shadow: var(--shadow); overflow: hidden; min-width: 0; }
    .term-bar { display: flex; align-items: center; gap: 7px; padding: 10px 14px; color: var(--term-dim); font-family: var(--font-mono); font-size: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
    .term-bar i { width: 9px; height: 9px; border-radius: 50%; background: rgba(255, 255, 255, 0.18); }
    .term-bar span { margin-left: 8px; }
    .term pre { margin: 0; padding: 16px; font-family: var(--font-mono); font-size: 13px; line-height: 1.7; overflow-x: auto; }
    .term .p { color: #b5a8f0; } .term .d { color: var(--term-dim); } .term .g { color: #7fcf78; }

    .duo { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 28px; }
    .dir { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
    .dir p { color: var(--text-2); }
    .dir .tag { display: inline-flex; align-items: center; gap: 8px; font-family: var(--font-mono); font-size: 12.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent); }
    .dir .tag svg { width: 16px; height: 16px; }

    .takeover { margin-top: 56px; display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 2fr); gap: 32px; padding-top: 36px; border-top: 1px solid var(--border); }
    .ways { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; }
    .ways div { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .ways strong { font-weight: 600; }
    .ways p { color: var(--text-2); font-size: 15.5px; }

    /* thread mock, drawn in the dashboard's own style */
    .split { display: grid; grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr); gap: 48px; align-items: start; }
    .points { display: flex; flex-direction: column; gap: 22px; list-style: none; margin: 0; padding: 0; }
    .points li { display: flex; flex-direction: column; gap: 4px; }
    .points strong { font-weight: 600; }
    .points span { color: var(--text-2); font-size: 16px; }
    .thread { border: 1px solid var(--border); border-radius: 10px; background: var(--panel); box-shadow: var(--shadow); font-size: 14px; overflow: hidden; min-width: 0; }
    .thread-head { display: flex; flex-direction: column; gap: 6px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
    .thread-head b { font-weight: 600; font-size: 15px; }
    .meta { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; color: var(--text-2); font-family: var(--font-mono); font-size: 12px; }
    .badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 8px; border-radius: 5px; background: var(--accent-soft); color: var(--accent); }
    .badge.ok { background: color-mix(in srgb, var(--ok) 13%, transparent); color: var(--ok); }
    .badge.idle { background: var(--panel-2); color: var(--text-2); }
    .badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .tabs { display: flex; gap: 4px; padding: 8px 12px; border-bottom: 1px solid var(--border); overflow-x: auto; }
    .tabs span { flex: none; padding: 4px 10px; border-radius: 6px; color: var(--text-2); font-size: 13px; }
    .tabs .on { background: var(--panel-2); color: var(--text); font-weight: 500; }
    .msgs { display: flex; flex-direction: column; gap: 12px; padding: 16px; }
    .msg { display: flex; flex-direction: column; gap: 3px; }
    .msg small { color: var(--text-2); font-family: var(--font-mono); font-size: 11.5px; }
    .msg.you { align-self: flex-end; max-width: 85%; padding: 8px 12px; border-radius: 8px; background: var(--panel-2); }
    .stack { display: flex; flex-direction: column; gap: 6px; padding: 12px 16px 16px; border-top: 1px solid var(--border); }
    .stack-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 10px; border-radius: 6px; background: var(--bg); border: 1px solid var(--border); }
    .stack-row span:first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* channels */
    .at { color: var(--accent); background: var(--accent-soft); padding: 0 4px; border-radius: 4px; }
    .grid6 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0; border-top: 1px solid var(--border); border-left: 1px solid var(--border); }
    .chan { display: flex; flex-direction: column; gap: 8px; padding: 24px; border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); min-width: 0; }
    .chan h3 { display: flex; align-items: center; gap: 10px; }
    .chan p { color: var(--text-2); font-size: 16px; }

    .grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 32px; }
    .grid4 div { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .grid4 p { color: var(--text-2); font-size: 16px; }

    /* safety band */
    .band { background: var(--band-bg); color: var(--band-text); }
    .band .eyebrow { color: color-mix(in srgb, var(--band-text) 65%, transparent); }
    .band h2 em { color: #e0a377; }
    .band-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 48px; align-items: center; }
    .band ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 14px; }
    .band li { display: flex; gap: 12px; color: color-mix(in srgb, var(--band-text) 82%, transparent); }
    .band li::before { content: ''; flex: none; width: 8px; height: 8px; margin-top: 9px; border-radius: 50%; background: #e0a377; }

    /* get started */
    .steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; counter-reset: step; }
    .step { display: flex; flex-direction: column; gap: 12px; min-width: 0; counter-increment: step; }
    .step h3::before { content: counter(step) '. '; color: var(--warm); font-style: italic; }
    .step p { color: var(--text-2); font-size: 16px; }
    .cmd { padding: 12px 14px; border-radius: 8px; background: var(--term-bg); color: var(--term-text); font-family: var(--font-mono); font-size: 13px; overflow-x: auto; white-space: nowrap; }

    .closing { text-align: center; padding-block: 72px 96px; }
    .closing .wrap { display: flex; flex-direction: column; align-items: center; gap: 22px; }
    .closing img { width: 150px; }
    .closing p { color: var(--text-2); max-width: 30em; }
    footer { border-top: 1px solid var(--border); color: var(--text-2); font-size: 14px; }
    footer .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px; padding-block: 22px; }

    @media (max-width: 860px) {
        .hero-grid, .duo, .split, .band-grid, .takeover, .steps { grid-template-columns: minmax(0, 1fr); }
        .scene { order: -1; max-width: 340px; }
        .ways, .grid4 { grid-template-columns: minmax(0, 1fr); }
        .grid6 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .nav-links a:not(.btn) { display: none; }
        section { padding-block: 60px; }
    }
    @media (max-width: 520px) { .grid6 { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>

<div class="wrap">
    <nav class="nav">
        <a class="wordmark" href="{{ route('home') }}" aria-label="Yak">Y<i>a</i>k<b>.</b></a>
        <div class="nav-links">
            <a href="#channels">Channels</a>
            <a href="#teleport">Teleport</a>
            <a href="#threads">Threads</a>
            <a class="btn btn-quiet" href="{{ route('login') }}">Sign in</a>
        </div>
    </nav>
</div>

<header class="hero">
    <div class="wrap">
        <div class="hero-grid">
            <div class="hero-copy">
                <span class="eyebrow">An open source coding agent for your team</span>
                <h1>Start it on your laptop. <em>Beam it up to Yak.</em></h1>
                <p class="lede">
                    Yak runs Claude Code in remote sandboxes. Hand it a task from Slack, Linear, Sentry,
                    GitHub, or email. Or teleport your own session from your terminal and it keeps
                    working there. Take over any thread whenever you want.
                </p>
                <p class="sub">
                    Either way, you get a pull request to review, with screenshots, a video walkthrough,
                    and a live preview of the branch.
                </p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="{{ route('login') }}">Sign in to the dashboard
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </a>
                    <div class="install">
                        <span><span class="dollar">$</span> <span id="install-command">curl -fsSL {{ route('install') }} | sh</span></span>
                        <button type="button" id="copy-install">Copy</button>
                    </div>
                </div>
            </div>

            <div class="scene">
                <div class="beam"></div>
                <img class="ufo" src="{{ asset('ufo.png') }}" alt="">
                <img class="mascot" src="{{ asset('mascot.png') }}" alt="Yak mascot">
            </div>
        </div>

        <div class="ribbon">
            <span class="eyebrow">Meets you where you are</span>
            <div class="channels">
                <span><i class="dot slack"></i>Slack</span>
                <span><i class="dot linear"></i>Linear</span>
                <span><i class="dot sentry"></i>Sentry</span>
                <span><i class="dot github"></i>GitHub</span>
                <span><i class="dot email"></i>Email</span>
                <span><i class="dot ci"></i>CI</span>
                <span><i class="dot"></i>Your terminal</span>
            </div>
        </div>
    </div>
</header>

<section id="channels">
    <div class="wrap">
        <div class="head">
            <span class="eyebrow">Meets you where you are</span>
            <h2>Mention it. <em>Assign it.</em> Forward it.</h2>
            <p>Flaky tests, Sentry bugs, copy tweaks, broken CSV exports. Hand Yak the small stuff from the tools your team already uses. It reports back in the same place.</p>
        </div>
        <div class="split">
            <div class="thread" aria-label="Example Slack conversation">
                <div class="thread-head"><div class="meta"><i class="dot slack"></i><span># support-eng</span></div></div>
                <div class="msgs">
                    <div class="msg"><small>Sam · 10:42</small><span><span class="at">@yak</span> the CSV export times out for accounts with 10k+ rows. Can you take a look?</span></div>
                    <div class="msg"><small>Yak · 10:42</small>On it. I will report back in this thread.</div>
                    <div class="msg"><small>Yak · 11:07</small><span>The PR is open: <span class="at">Geocodio/geocodio#2841</span> Chunk the export query. CI is green. A video walkthrough and a preview link are in the PR.</span></div>
                    <div class="msg"><small>Sam · 11:15</small>Looks right. Reviewing now.</div>
                </div>
            </div>
            <div class="grid6">
                <div class="chan"><h3><i class="dot slack"></i>Slack</h3><p>Mention <code>@yak</code> in any thread. It replies there with the PR or the answer.</p></div>
                <div class="chan"><h3><i class="dot linear"></i>Linear</h3><p>Assign an issue to Yak. It comments back when the PR is open.</p></div>
                <div class="chan"><h3><i class="dot sentry"></i>Sentry</h3><p>When an alert fires, Yak investigates and opens a PR with a fix.</p></div>
                <div class="chan"><h3><i class="dot github"></i>GitHub</h3><p>Comment <code>/yak</code> on a pull request, or let Yak review every open PR.</p></div>
                <div class="chan"><h3><i class="dot email"></i>Email</h3><p>Forward any email to Yak and move on. Only DKIM-verified senders get through.</p></div>
                <div class="chan"><h3><i class="dot ci"></i>CI</h3><p>Yak scans builds for flaky tests and drafts the fix before someone has to ask.</p></div>
            </div>
        </div>
    </div>
</section>

<section id="teleport">
    <div class="wrap">
        <div class="head">
            <span class="eyebrow">Teleport</span>
            <h2>Two directions. <em>One conversation.</em></h2>
            <p>A thread moves between your computer and Yak with its full conversation. Only committed work travels, through GitHub, as a fast-forward.</p>
        </div>

        <div class="duo">
            <div class="dir">
                <span class="tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>Up: your terminal to Yak</span>
                <h3>Out of time? Hand the session to a sandbox.</h3>
                <div class="term">
                    <div class="term-bar"><i></i><i></i><i></i><span>claude · ~/code/geocodio</span></div>
<pre><span class="p">&gt;</span> /yak teleport "finish the CSV export fix"

<span class="d">  Pushed yak/csv-export-fix to github.com/Geocodio/geocodio
  Uploaded the session (412 KB)</span>
<span class="g">  Yak has the thread. This session is now read-only.</span></pre>
                </div>
                <p>Yak commits your work, pushes a branch, and continues the same conversation in its own sandbox. Follow along in the dashboard.</p>
            </div>

            <div class="dir">
                <span class="tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>Down: Yak to your terminal</span>
                <h3>Want the last mile? Pull the thread down.</h3>
                <div class="term">
                    <div class="term-bar"><i></i><i></i><i></i><span>zsh · ~/code/geocodio</span></div>
<pre><span class="p">$</span> yak teleport 4812

<span class="d">  Yak committed its work and pushed yak/csv-export-fix</span>
<span class="g">  Thread 4812 is now on this computer.</span>
<span class="d">  Starting claude --resume 7c1e52aa in ~/code/geocodio</span></pre>
                </div>
                <p>Works for any thread, including one Yak started from a Sentry alert or a Slack mention. If your checkout is dirty, Yak offers a stash or a worktree.</p>
            </div>
        </div>

        <div class="takeover">
            <h3>Three ways to <em>take over</em> a thread</h3>
            <div class="ways">
                <div><strong>Teleport to your computer</strong><p>One button on the thread page, or <code>yak teleport</code> from your checkout.</p></div>
                <div><strong>Browser terminal</strong><p>A shell inside the thread's sandbox, right on the thread page.</p></div>
                <div><strong>Plain SSH</strong><p><code>yak ssh 4812</code> connects with the keys you already have on GitHub.</p></div>
            </div>
        </div>
    </div>
</section>

<section id="threads">
    <div class="wrap">
        <div class="head">
            <span class="eyebrow">Threads</span>
            <h2>Every piece of work is a <em>thread.</em></h2>
            <p>A thread is one conversation with its own sandbox. The task, the follow-ups, and the retries all live in one place.</p>
        </div>

        <div class="split">
            <ul class="points">
                <li><strong>A sandbox that sleeps and wakes</strong><span>Each thread keeps its environment. It hibernates when idle and comes back when you send a message.</span></li>
                <li><strong>Steer it mid-turn</strong><span>Send a message while Yak works and it changes course. Stop it or tell it to continue at any time.</span></li>
                <li><strong>Sub-threads</strong><span>A thread can start child threads for research, implementation, or a mockup, and keep working while they run.</span></li>
                <li><strong>Several repos, one thread</strong><span>Each changed repo gets its own PR, linked to its siblings with a merge order.</span></li>
                <li><strong>Private when you want</strong><span>A private thread is visible only to you. Push notifications tell you when a thread needs you.</span></li>
            </ul>

            <div class="thread" aria-label="Example thread page">
                <div class="thread-head">
                    <b>Fix CSV export for accounts with 10k+ rows</b>
                    <div class="meta"><span class="badge">Running turn 3</span><span>Geocodio/geocodio</span><span>yak/csv-export-fix</span></div>
                </div>
                <div class="tabs"><span class="on">Conversation</span><span>Changes</span><span>Preview</span><span>Terminal</span><span>Files</span><span>Pull requests</span></div>
                <div class="msgs">
                    <div class="msg"><small>Yak</small>The export job loads every row into memory. I am switching it to a chunked query and adding a test with 25,000 rows.</div>
                    <div class="msg you"><small>You, mid-turn</small>Keep the column order the same as the old export.</div>
                    <div class="msg"><small>Yak</small>Understood. I kept the original column order and added an assertion for it.</div>
                </div>
                <div class="stack">
                    <div class="stack-row"><span>Research: where else is the exporter used?</span><span class="badge ok">Done</span></div>
                    <div class="stack-row"><span>Implement: chunked query in ExportJob</span><span class="badge">Running</span></div>
                    <div class="stack-row"><span>Mockup: progress bar on the export page</span><span class="badge idle">Asleep</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="wrap">
        <div class="head">
            <span class="eyebrow">Proof of work</span>
            <h2>Every PR comes with <em>proof.</em></h2>
        </div>
        <div class="grid4">
            <div><h3>Screenshots and video</h3><p>Every UI change arrives with a screenshot and a video walkthrough.</p></div>
            <div><h3>Live preview</h3><p>Each thread serves a preview of its own branch. Click around before you read the diff.</p></div>
            <div><h3>Answers, not only PRs</h3><p>Ask how something works. Yak researches the codebase and writes back.</p></div>
            <div><h3>PR reviews</h3><p>Rubric-driven reviews on open PRs, with suggestion blocks you can apply.</p></div>
        </div>
    </div>
</section>

<section class="band">
    <div class="wrap">
        <div class="band-grid">
            <div class="head" style="margin-bottom:0">
                <span class="eyebrow">The safety model</span>
                <h2>Yak opens PRs. <em>Humans merge.</em></h2>
            </div>
            <ul>
                <li>Yak never merges and never touches production. Its output is a pull request and a preview.</li>
                <li>Each thread runs in an isolated sandbox with its own environment.</li>
                <li>Teleport moves committed work only, through GitHub. Uncommitted files stay on your computer unless you choose to commit them.</li>
            </ul>
        </div>
    </div>
</section>

<section id="start">
    <div class="wrap">
        <div class="head">
            <span class="eyebrow">Get teleport</span>
            <h2>Three commands to your <em>first beam-up.</em></h2>
        </div>
        <div class="steps">
            <div class="step"><h3>Install</h3><div class="cmd">curl -fsSL {{ route('install') }} | sh</div><p>Adds the Yak plugin to Claude Code and the <code>yak</code> command. macOS and Linux.</p></div>
            <div class="step"><h3>Sign in</h3><div class="cmd">yak login</div><p>Shows a code and opens an approval page in your browser.</p></div>
            <div class="step"><h3>Teleport</h3><div class="cmd">/yak teleport</div><p>Run it inside any Claude Code session in a repo that Yak knows.</p></div>
        </div>
    </div>
</section>

<section class="closing">
    <div class="wrap">
        <img src="{{ asset('mascot.png') }}" alt="">
        <h2>Y<em>a</em>k<em style="font-style:normal">.</em></h2>
        <p>Sign in to watch your threads, steer them, or beam one down.</p>
        <a class="btn btn-primary" href="{{ route('login') }}">Sign in to the dashboard</a>
    </div>
</section>

<footer><div class="wrap"><span>Yak is open source.</span><a href="https://github.com/Geocodio/yak">GitHub</a></div></footer>

<script>
// Play the beam-up once on load. After that, only hover replays it.
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var scene = document.querySelector('.scene');
    setTimeout(function () { scene.classList.add('beaming'); }, 1200);
    setTimeout(function () { scene.classList.remove('beaming'); }, 6200);
}
document.getElementById('copy-install').addEventListener('click', function (event) {
    var button = event.currentTarget;
    navigator.clipboard.writeText(document.getElementById('install-command').textContent).then(
        function () { button.textContent = 'Copied'; setTimeout(function () { button.textContent = 'Copy'; }, 1500); },
        function () { window.getSelection().selectAllChildren(document.getElementById('install-command')); }
    );
});
</script>

</body>
</html>
