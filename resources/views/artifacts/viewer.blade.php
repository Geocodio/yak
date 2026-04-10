<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $artifact->filename }} - Artifact Viewer - Yak</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --yak-slate: #3d4f5f;
            --yak-orange: #c4744a;
            --yak-orange-warm: #d4915e;
            --yak-cream: #f5f0e8;
            --yak-tan: #c8b89a;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            color: var(--yak-slate);
            background: var(--yak-cream);
            -webkit-font-smoothing: antialiased;
        }

        .header-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 56px;
            background: var(--yak-slate);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-wordmark {
            font-family: 'Instrument Serif', serif;
            font-size: 20px;
            color: white;
            user-select: none;
        }

        .header-wordmark .accent {
            font-style: italic;
            color: var(--yak-orange);
        }

        .header-divider {
            width: 1px;
            height: 24px;
            background: rgba(255,255,255,0.2);
        }

        .header-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            transition: opacity 0.15s;
        }

        .header-back:hover { opacity: 0.8; }

        .header-back svg { width: 16px; height: 16px; }

        .header-filename {
            font-size: 14px;
            color: rgba(255,255,255,0.6);
        }

        .viewer-content {
            padding-top: 56px;
            min-height: 100vh;
        }

        .viewer-card {
            background: white;
            border: 1px solid rgba(200,184,154,0.4);
            border-radius: 28px;
            box-shadow: 0 4px 6px rgba(61,79,95,0.03), 0 12px 24px rgba(61,79,95,0.06);
            max-width: 1200px;
            margin: 24px auto;
            min-height: calc(100vh - 104px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .viewer-card iframe {
            flex: 1;
            width: 100%;
            min-height: calc(100vh - 104px);
            border: none;
            border-radius: 0 0 28px 28px;
        }
    </style>
</head>
<body>

<header class="header-bar">
    <div class="header-left">
        <span class="header-wordmark">Y<span class="accent">a</span>k</span>
        <span class="header-divider"></span>
        <a href="{{ route('tasks.show', $task) }}" class="header-back" data-testid="back-to-task">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
            </svg>
            Back to Task #{{ $task->id }}
        </a>
    </div>
    <div class="header-right">
        <span class="header-filename">{{ $artifact->filename }}</span>
    </div>
</header>

<main class="viewer-content">
    <div class="viewer-card">
        <iframe src="{{ route('artifacts.show', ['task' => $task->id, 'filename' => $artifact->filename]) }}" title="{{ $artifact->filename }}" sandbox="allow-same-origin" data-testid="artifact-iframe"></iframe>
    </div>
</main>

</body>
</html>
