import assert from 'node:assert/strict';
import { test } from 'node:test';
import { changedLines } from './changedLines.ts';

test('unchanged text marks nothing', () => {
    assert.deepEqual(changedLines('a\nb\nc\n', 'a\nb\nc\n'), []);
});

test('an inserted line is marked', () => {
    assert.deepEqual(changedLines('a\nc\n', 'a\nb\nc\n'), [2]);
});

test('a modified middle line is marked', () => {
    assert.deepEqual(changedLines('a\nb\nc\n', 'a\nB\nc\n'), [2]);
});

test('a null head marks nothing', () => {
    assert.deepEqual(changedLines(null, 'a\nb\n'), []);
});
