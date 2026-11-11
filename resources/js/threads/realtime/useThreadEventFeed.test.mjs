import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initialFeed, reduceFeed } from './feedReducer.ts';

const at = '2026-11-11T00:00:00Z';
const user = (sequence) => ({ sequence, type: 'entry', createdAt: at, data: { role: 'user', kind: 'prompt', text: 'hi' } });
const apply = (feed, ...events) => reduceFeed(feed, { kind: 'events', events });
const aligned = (feed) => assert.equal(feed.entrySequences.length, feed.state.entries.length);

test('records the sequence of each appended entry', () => {
    const feed = apply(initialFeed(), user(3), user(5));

    assert.deepEqual(feed.entrySequences, [3, 5]);
    aligned(feed);
});

test('stays aligned through duplicate drops', () => {
    const feed = apply(apply(initialFeed(), user(1), user(2)), user(2), user(1), user(3));

    assert.deepEqual(feed.entrySequences, [1, 2, 3]);
    aligned(feed);
});

test('stays aligned through gap catch-up with non-entry events and latestSequence', () => {
    const delta = { sequence: 2, type: 'delta', createdAt: at, data: { blockKey: 'm:0', kind: 'text', text: 'x' } };
    const feed = reduceFeed(apply(initialFeed(), user(1)), { kind: 'events', events: [delta, user(3), user(4)], latestSequence: 9 });

    assert.deepEqual(feed.entrySequences, [1, 3, 4]);
    assert.equal(feed.state.lastSequence, 9);
    aligned(feed);
});

test('stream.reset keeps entries and sequences; reset clears both', () => {
    const reset = { sequence: 3, type: 'stream.reset', createdAt: at, data: {} };
    const feed = apply(apply(initialFeed(), user(1), user(2)), reset);

    assert.deepEqual(feed.entrySequences, [1, 2]);
    aligned(feed);

    const cleared = reduceFeed(feed, { kind: 'reset' });

    assert.deepEqual(cleared.entrySequences, []);
    aligned(cleared);
});
