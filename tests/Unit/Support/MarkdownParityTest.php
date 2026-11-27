<?php

use App\Support\Markdown;

/**
 * The browser renderer (resources/js/threads/streaming/streamingMarkdown.ts)
 * is checked against the same recorded HTML, so both renderers agree with
 * the server. Re-record with UPDATE_MARKDOWN_PARITY=1.
 */
function normalizeParityHtml(string $html): string
{
    return trim((string) preg_replace('/>\s+</', '><', $html));
}

dataset('markdownParitySamples', function () {
    foreach (glob(__DIR__ . '/../../Fixtures/markdown-parity/*.md') as $path) {
        yield basename($path, '.md') => [$path];
    }
});

it('the server renders every parity sample as recorded', function (string $path) {
    $htmlPath = preg_replace('/\.md$/', '.html', $path);
    $html = Markdown::toHtml((string) file_get_contents($path));

    if (getenv('UPDATE_MARKDOWN_PARITY') === '1') {
        file_put_contents($htmlPath, $html);
    }

    expect(normalizeParityHtml($html))->toBe(normalizeParityHtml((string) file_get_contents($htmlPath)));
})->with('markdownParitySamples');
