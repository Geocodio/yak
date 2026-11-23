import assert from 'node:assert/strict';
import { test } from 'node:test';
import { placeContinuedRows } from './placeContinuedRows.ts';

const event = (sequence) => ({ type: 'event', item: { sequence } });
const person = { type: 'person' };

test('a continued row sits before the first event of the run the agent started', () => {
    const entries = [person, event(2), event(5), event(9), event(12)];
    const runs = [{ runId: '10', afterSequence: 5 }, { runId: '11', afterSequence: 12 }];

    assert.deepEqual([...placeContinuedRows(entries, runs, new Map([[11, ['agent']]]))], [[3, [['agent']]]]);
});

test('a first run has no earlier run, so the row sits before its first event', () => {
    assert.deepEqual([...placeContinuedRows([event(3), event(4)], [{ runId: '7', afterSequence: 4 }], new Map([[7, ['a', 'b']]]))], [[0, [['a', 'b']]]]);
});

test('a run without events in the conversation gets no row', () => {
    assert.equal(placeContinuedRows([event(2)], [{ runId: '10', afterSequence: 2 }, { runId: '11', afterSequence: 2 }], new Map([[11, ['agent']]])).size, 0);
    assert.equal(placeContinuedRows([event(2)], [], new Map([[11, ['agent']]])).size, 0);
});

test('two runs landing on one entry keep their agents in separate groups', () => {
    const entries = [event(3)];
    const runs = [{ runId: '7', afterSequence: 0 }, { runId: '8', afterSequence: 0 }];

    assert.deepEqual([...placeContinuedRows(entries, runs, new Map([[7, ['a']], [8, ['b']]]))], [[0, [['a'], ['b']]]]);
});
