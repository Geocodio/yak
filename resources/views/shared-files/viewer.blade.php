<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $file->filename }} - Yak</title>
    <style>
        :root { color-scheme: light dark; --bg: #eceef1; --panel: #fff; --hair: #dcdfe4; --body: #1c2128; --ok: #2f7d4f; --ok-soft: rgba(47, 125, 79, .12); --accent: #b45c35; --accent-ink: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c0d10; --panel: #17181d; --hair: #2b2d35; --body: #e7e9ee; --ok: #6fc08f; --ok-soft: rgba(111, 192, 143, .14); --accent: #d58f71; --accent-ink: #101014; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); color: var(--body); font: 14px/1.5 Inter, system-ui, sans-serif; }
        header { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; padding: 10px 16px; background: var(--panel); border-bottom: 1px solid var(--hair); }
        .name { flex: 1; min-width: 0; font-weight: 500; overflow-wrap: anywhere; }
        .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--ok-soft); color: var(--ok); }
        .pill::before { content: ""; width: 7px; height: 7px; border-radius: 999px; background: var(--ok); }
        .download { font-size: 13px; padding: 5px 10px; border-radius: 8px; background: var(--accent); color: var(--accent-ink); text-decoration: none; }
        iframe { flex: 1; width: 100%; min-height: calc(100vh - 56px); border: 0; background: #fff; }
    </style>
</head>
<body>
    <header>
        <span class="name" data-testid="shared-file-name">{{ $file->filename }}</span>
        @if($file->shared_with_team)
            <span class="pill" data-testid="shared-file-team-pill">Shared with the team</span>
        @endif
        <a class="download" href="{{ $downloadUrl }}" data-testid="shared-file-download">Download</a>
    </header>
    <iframe src="{{ $rawUrl }}" title="{{ $file->filename }}" sandbox="allow-same-origin" data-testid="shared-file-frame"></iframe>
</body>
</html>
