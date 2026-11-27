import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseAnsi } from './ansi.ts';

test('plain text is one unstyled segment per line', () => {
    assert.deepEqual(parseAnsi('a\nb'), [[{ text: 'a', className: null }], [{ text: 'b', className: null }]]);
});

test('colors and bold map to theme classes and reset', () => {
    assert.deepEqual(parseAnsi('\x1b[90m.\x1b[39m \x1b[32;1mDONE\x1b[39;22m'), [
        [
            { text: '.', className: 'text-faint' },
            { text: ' ', className: null },
            { text: 'DONE', className: 'text-ok font-semibold' },
        ],
    ]);
});

test('a color carries across a line break', () => {
    assert.deepEqual(parseAnsi('\x1b[31mone\ntwo\x1b[0m'), [[{ text: 'one', className: 'text-fail' }], [{ text: 'two', className: 'text-fail' }]]);
});

test('backgrounds, extended colors and cursor codes are dropped', () => {
    assert.deepEqual(parseAnsi('\x1b[37;44m INFO \x1b[39;49m\x1b[38;5;208mx\x1b[2K\x1b[1G\x1b]0;title\x07'), [
        [
            { text: ' INFO ', className: 'text-body' },
            { text: 'x', className: null },
        ],
    ]);
});
