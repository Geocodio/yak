import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextStreamingLatch } from './nextStreamingLatch.ts';

const base = { previous: false, threadChanged: false, working: true, anyStreaming: false };

test('turns on when text streams in a running turn', () => {
    assert.equal(nextStreamingLatch({ ...base, anyStreaming: true }), true);
});

test('stays on across a gap between blocks', () => {
    assert.equal(nextStreamingLatch({ ...base, previous: true }), true);
});

test('stays off before anything streamed', () => {
    assert.equal(nextStreamingLatch(base), false);
});

test('turns off when the turn ends', () => {
    assert.equal(nextStreamingLatch({ ...base, previous: true, working: false }), false);
});

test('turns off on a thread change until the new thread streams', () => {
    assert.equal(nextStreamingLatch({ ...base, previous: true, threadChanged: true }), false);
    assert.equal(nextStreamingLatch({ ...base, previous: true, threadChanged: true, anyStreaming: true }), true);
});
