import assert from 'node:assert/strict';
import { test } from 'node:test';
import { findGap, initialEventState, reduceEvents } from './reducer.ts';

const at = '2026-11-11T00:00:00Z';
const user = (sequence, text = 'hi') => ({ sequence, type: 'entry', createdAt: at, data: { role: 'user', kind: 'prompt', text } });
const delta = (sequence, text, blockKey = 'm1:0') => ({ sequence, type: 'delta', createdAt: at, data: { blockKey, kind: 'text', text } });
const assistant = (sequence) => ({
    sequence,
    type: 'entry',
    createdAt: at,
    data: { role: 'assistant', messageId: 'm1', blocks: [{ type: 'text', text: 'done' }] },
});
const apply = (state, ...events) => reduceEvents(state, { kind: 'events', events });

test('applies in-order events', () => {
    const state = apply(initialEventState(), user(1), user(2));

    assert.equal(state.entries.length, 2);
    assert.equal(state.lastSequence, 2);
});

test('drops duplicates', () => {
    const state = apply(apply(initialEventState(), user(1), user(2)), user(2), user(1));

    assert.equal(state.entries.length, 2);
    assert.equal(state.lastSequence, 2);
});

test('reports a gap for an out-of-order event or pointer', () => {
    const state = apply(initialEventState(), user(1), user(2));

    assert.equal(findGap(state, user(3)), null);
    assert.deepEqual(findGap(state, user(5)), { fromAfter: 2 });
    assert.deepEqual(findGap(state, { sequence: 9, pointer: true }), { fromAfter: 2 });
});

test('accumulates deltas until the assistant entry clears them', () => {
    let state = apply(initialEventState(), delta(1, 'he'), delta(2, 'llo'), delta(3, 'x', 'other:0'));

    assert.equal(state.streamingBlocks['m1:0'].text, 'hello');
    state = apply(state, assistant(4));
    assert.equal('m1:0' in state.streamingBlocks, false);
    assert.equal(state.streamingBlocks['other:0'].text, 'x');
    assert.equal(state.entries.length, 1);
});

test('stream.reset empties the streaming blocks', () => {
    const state = apply(apply(initialEventState(), delta(1, 'he')), { sequence: 2, type: 'stream.reset', createdAt: at, data: {} });

    assert.deepEqual(state.streamingBlocks, {});
});

test('keeps tool_result entries in order and tracks turn and thread state', () => {
    const toolResult = { sequence: 2, type: 'entry', createdAt: at, data: { role: 'tool_result', toolUseId: 't', isError: false, text: 'ok' } };
    const state = apply(
        initialEventState(),
        user(1),
        toolResult,
        { sequence: 3, type: 'turn', createdAt: at, data: { turnId: 7, state: 'running' } },
        { sequence: 4, type: 'thread', createdAt: at, data: { status: 'open', sandboxState: 'running', runnerState: 'ready', blockedReason: null } },
    );

    assert.deepEqual(state.entries.map((entry) => entry.role), ['user', 'tool_result']);
    assert.deepEqual(state.turn, { turnId: 7, state: 'running' });
    assert.equal(state.thread.status, 'open');
});

test('catch-up latestSequence advances lastSequence past dropped deltas', () => {
    const state = reduceEvents(initialEventState(), { kind: 'events', events: [user(1), user(3)], latestSequence: 6 });

    assert.equal(state.lastSequence, 6);
});

test('viewers, connection and reset actions', () => {
    let state = reduceEvents(initialEventState(), { kind: 'viewers', viewers: [{ id: 1, name: 'A' }] });
    state = reduceEvents(state, { kind: 'connection', connection: 'live' });
    assert.equal(state.viewers.length, 1);
    assert.equal(state.connection, 'live');
    assert.equal(reduceEvents(state, { kind: 'reset' }).viewers.length, 0);
});

test('reconnect journey: pointer gap, catch-up applies once in order, replayed live events are dropped', () => {
    let state = apply(initialEventState(), user(1), user(2), user(3), user(4));

    const gap = findGap(state, { sequence: 9, pointer: true });
    assert.deepEqual(gap, { fromAfter: 4 });

    const catchUp = [user(5), delta(6, 'a'), user(7), user(8), user(9)];
    state = reduceEvents(state, { kind: 'events', events: catchUp, latestSequence: 9 });
    assert.equal(state.lastSequence, 9);
    assert.equal(state.entries.length, 8);

    state = apply(state, user(9), user(10));
    assert.equal(state.entries.length, 9);
    assert.equal(state.lastSequence, 10);
});
