import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.window = { location: { hash: '#card-question' } };

const { decodeFragment, openingFragment } = await import('./initialFragment.ts');

test('a malformed percent sequence is used as written instead of throwing', () => {
    assert.equal(decodeFragment('%E0%A4%A'), '%E0%A4%A');
    assert.equal(decodeFragment('message-a%20b'), 'message-a b');
});

test('the opening fragment belongs to the first thread only', () => {
    assert.equal(openingFragment('thread-a'), 'card-question');
    assert.equal(openingFragment('thread-a'), 'card-question');
    assert.equal(openingFragment('thread-b'), '');
    assert.equal(openingFragment('thread-a'), '');
});
