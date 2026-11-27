import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pickHtml } from './streamedHtml.ts';

test('keeps the streamed html when it matches the final html', () => {
    const streamed = '<p>Hello <em>there</em></p>';

    assert.equal(pickHtml(streamed, '<p>Hello <em>there</em></p>\n'), streamed);
    assert.equal(pickHtml('<p>a</p><p>b</p>', '<p>a</p>\n<p>b</p>\n'), '<p>a</p><p>b</p>');
});

test('takes the final html when they differ', () => {
    assert.equal(pickHtml('<p>Hello</p>', '<p>Hello!</p>'), '<p>Hello!</p>');
});

test('takes the final html when nothing streamed', () => {
    assert.equal(pickHtml(null, '<p>Hi</p>'), '<p>Hi</p>');
});
