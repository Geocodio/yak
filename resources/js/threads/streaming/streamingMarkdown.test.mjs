import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    normalizeHtml,
    renderMarkdown,
    renderStreamingMarkdown,
    stripClarificationBlock,
} from './streamingMarkdown.ts';

const directory = new URL('../../../../tests/Fixtures/markdown-parity/', import.meta.url);
const names = readdirSync(directory)
    .filter((file) => file.endsWith('.md'))
    .map((file) => file.slice(0, -3));
const read = (name, extension) => readFileSync(new URL(`${name}.${extension}`, directory), 'utf8');

const entities = { amp: '&', lt: '<', gt: '>', quot: '"', copy: '©' };
const textOf = (html) =>
    html
        .replace(/<[^>]*>/g, '')
        .replace(/&(#x[0-9a-f]+|#\d+|\w+);/gi, (match, code) => {
            if (code.startsWith('#x')) return String.fromCodePoint(parseInt(code.slice(2), 16));
            if (code.startsWith('#')) return String.fromCodePoint(parseInt(code.slice(1), 10));
            return entities[code] ?? match;
        });
const squash = (text) => text.replace(/\s+/g, ' ');

test('renderMarkdown matches the server for every parity sample', () => {
    assert.ok(names.length >= 18);
    for (const name of names) {
        assert.equal(normalizeHtml(renderMarkdown(read(name, 'md'))), normalizeHtml(read(name, 'html')), name);
    }
});

test('every prefix renders text the final reply starts with', () => {
    for (const name of names) {
        const full = read(name, 'md');
        const finalText = squash(textOf(renderMarkdown(stripClarificationBlock(full))));
        for (let length = 0; length <= full.length; length++) {
            const shown = squash(textOf(renderStreamingMarkdown(full.slice(0, length)))).trimEnd();
            assert.ok(
                finalText.startsWith(shown),
                `${name} @${length} ${JSON.stringify(full.slice(0, length).slice(-30))}: ${JSON.stringify(shown.slice(-40))}`,
            );
        }
    }
});

test('no prefix leaves a fence, bold, emphasis or code span open', () => {
    const outsideCode = (html) => textOf(html.replace(/<pre[\s\S]*?<\/pre>/g, '').replace(/<code>[\s\S]*?<\/code>/g, ''));
    const count = (text, marker) => text.split(marker).length - 1;
    for (const name of names) {
        const full = read(name, 'md');
        const finalOutside = outsideCode(renderMarkdown(stripClarificationBlock(full)));
        for (let length = 0; length <= full.length; length++) {
            const outside = outsideCode(renderStreamingMarkdown(full.slice(0, length)));
            for (const marker of ['```', '**', '*', '_', '~', '`']) {
                assert.ok(count(outside, marker) <= count(finalOutside, marker), `${name} @${length} ${marker}`);
            }
        }
    }
});

test('settles emphasis across a paragraph and leaves identifiers alone', () => {
    assert.match(renderStreamingMarkdown('and _under scor'), /<em>under scor<\/em>/);
    assert.match(renderStreamingMarkdown('This is **bold across\nlines and more'), /<strong>bold across\nlines and more<\/strong>/);
    assert.match(renderStreamingMarkdown('See snake_case and foo__bar now'), /snake_case and foo__bar now/);
    assert.match(renderStreamingMarkdown('Use ``a ` ba'), /<code>a ` ba<\/code>/);
});

test('hides a clarification fence from its opening line', () => {
    const text = 'Need answers.\n\n```clarification\n{"questions":[';
    assert.equal(textOf(renderStreamingMarkdown(text)).trim(), 'Need answers.');
    assert.equal(textOf(renderStreamingMarkdown('Need answers.\n\n```clarif')).trim(), 'Need answers.');
    assert.ok(!renderStreamingMarkdown(text).includes('<pre'));
});

test('holds a table until its delimiter row', () => {
    assert.ok(!renderStreamingMarkdown('Intro\n\n| a | b |\n| c | d |\n').includes('|'));
    assert.ok(renderStreamingMarkdown('Intro\n\n| a | b |\n|---|---|\n| c | d |\n').includes('<table>'));
});

test('renders a link whose url is still arriving as a link', () => {
    const html = renderStreamingMarkdown('See [the docs](https://exam');
    assert.match(html, /<a[^>]*>the docs<\/a>/);
    assert.ok(!html.includes('](')); 
});

test('never formats a marker that cannot open, and never adds a closer the source lacks', () => {
    assert.equal(renderStreamingMarkdown('Roughly ~200 lines'), '<p>Roughly ~200 lines</p>');
    assert.equal(renderStreamingMarkdown('git diff HEAD~1 sho'), '<p>git diff HEAD~1 sho</p>');
    assert.equal(renderStreamingMarkdown('Result: 3 * 4'), '<p>Result: 3 * 4</p>');
    assert.equal(renderStreamingMarkdown('Match *.php fil'), '<p>Match *.php fil</p>');
    assert.equal(renderStreamingMarkdown('Then a*b sta'), '<p>Then a*b sta</p>');
    assert.match(renderStreamingMarkdown('and ~~stru'), /<del>stru<\/del>/);
    assert.match(renderStreamingMarkdown('and **bol'), /<strong>bol<\/strong>/);
});
