import assert from 'node:assert/strict';
import { test } from 'node:test';
import { splitNewThreadQuery } from './newThreadPrompt.ts';

const keywords = ['create', 'new', 'start', 'spawn', 'task'];

test('a keyword followed by words becomes the prompt', () => {
    assert.deepEqual(splitNewThreadQuery('new fix the login bug', keywords), { prompt: 'fix the login bug' });
    assert.deepEqual(splitNewThreadQuery('create  x', keywords), { prompt: 'x' });
});

test('a bare keyword or a longer word is not a split', () => {
    assert.equal(splitNewThreadQuery('new', keywords), null);
    assert.equal(splitNewThreadQuery('renew plan', keywords), null);
});
