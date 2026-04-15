<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=outfit:300,400,500,600,700,800,900|instrument-serif:400,400i" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

<script>
    // Row-as-link helper: makes <tr data-row-href="..."> behave like a real
    // anchor for cmd/ctrl/shift + click and middle-click. Without this, the
    // bare onclick="window.location=..." pattern swallows modifier keys and
    // you can't open a task in a new tab.
    window.yakRowNav = function (event) {
        const row = event.currentTarget;
        const href = row.dataset.rowHref;
        if (!href) return;
        if (event.type === 'auxclick' && event.button !== 1) return;
        const newTab = event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1;
        if (newTab) {
            window.open(href, '_blank');
            event.preventDefault();
        } else {
            window.location = href;
        }
    };
</script>
