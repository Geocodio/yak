**How to write the report:**

Research properly before you write. You have a full shell in the sandbox, so compute things rather than estimating them: run the numbers, parse the data, benchmark both options, prototype the approach and see whether it works. A report with a real measurement in it beats one with a plausible guess. Prefer one verified fact over three plausible ones, and say which is which.

Start from the house template below. Copy it to `.yak-artifacts/research.html` and fill the marked regions. Keep its stylesheet and add to it rather than replacing it, so every Yak report reads as one publication. The section order is the required structure: request, bottom line, key figures, what I found, what I would do, where I might be wrong, sources. Drop the key figures block when you have no numbers you actually obtained.

The page must be self-contained: no external stylesheets, scripts, or images. The only external request allowed is the Google Fonts link already in the template, and the page must still read correctly when it fails.

Visuals are the point of using HTML at all:

- Draw charts and diagrams as inline SVG, by hand. No chart libraries and no image files. Use the template's `.svg-*` and `.s1` to `.s4` classes rather than hardcoded colours, or the figure will vanish in one of the two themes.
- Pick the chart form from the question: bars to compare amounts, a line for change over time, a table when the reader needs exact values. A diagram of boxes and arrows explains a mechanism better than any chart.
- NEVER chart a number you did not actually obtain. A fabricated axis is worse than no figure. Without real numbers, draw the mechanism instead: where the data flows, which component calls which, where the time goes.
- Place every bar, tick, and label with one scale, and leave room in the viewBox for the outermost labels.
- Two or three figures that carry real information beat six that fill space. A report with no figure is fine when the finding is textual.
- Use the table for comparisons, the key figures block for numbers you measured, and `<pre><code>` for the script or query behind each number so it can be re-run.
- Do not put a coloured left border on any box. Tint the whole surface or use a status dot instead.

Before you finish, check that the file parses as HTML and that every tag you opened is closed. A broken page is worse than a plain one.

<template-file path=".yak-artifacts/research.html">
{!! file_get_contents(resource_path('views/prompts/partials/research-report-template.html')) !!}
</template-file>
