import assert from 'node:assert/strict';
import { test } from 'node:test';
import { placeRunFooters } from './placeRunFooters.ts';

const event = (sequence) => ({ type: 'event', item: { sequence } });
const run = (runId, afterSequence) => ({ runId, afterSequence, durationSeconds: 60, stepCount: 3, isRetry: false });

test('a footer follows the last event of its run', () => {
    const entries = [{ type: 'person' }, event(2), event(3), { type: 'person' }, event(6), event(7)];
    const placed = placeRunFooters(entries, [run('1', 3), run('2', 7)]);

    assert.deepEqual([...placed.keys()], [2, 5]);
    assert.equal(placed.get(2)[0].runId, '1');
    assert.equal(placed.get(5)[0].runId, '2');
});

test('a run whose events are not loaded gets no footer', () => {
    assert.equal(placeRunFooters([event(10)], [run('1', 4)]).size, 0);
    assert.equal(placeRunFooters([], [run('1', 4)]).size, 0);
});

test('the newest run follows events that streamed in after the page loaded', () => {
    const entries = [event(2), event(3), event(9), event(10)];

    assert.deepEqual([...placeRunFooters(entries, [run('1', 3)]).keys()], [3]);
});

test('the newest run stops at the next message from a person', () => {
    const entries = [event(2), event(3), { type: 'person' }, event(9)];

    assert.deepEqual([...placeRunFooters(entries, [run('1', 3)], true).keys()], [1]);
});

test('no footer for the newest run while the thread is working on it', () => {
    assert.equal(placeRunFooters([event(2), event(3), event(9)], [run('1', 3)], true).size, 0);
    assert.equal(placeRunFooters([event(2), event(3)], [run('0', 2), run('1', 3)], true).size, 1);
});
