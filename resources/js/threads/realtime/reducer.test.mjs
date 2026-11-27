import assert from 'node:assert/strict';
import { test } from 'node:test';
import { findGap, initialEventState, reduceEvents } from './reducer.ts';

const at = '2026-11-11T00:00:00Z';
const user = (sequence, text = 'hi') => ({ sequence, type: 'entry', createdAt: at, data: { role: 'user', kind: 'prompt', text } });
const delta = (sequence, text, blockKey = 'm1:0', offset = 0) => ({ sequence, type: 'delta', createdAt: at, data: { blockKey, kind: 'text', text, offset } });
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
    let state = apply(initialEventState(), delta(1, 'he'), delta(2, 'llo', 'm1:0', 2), delta(3, 'x', 'other:0'));

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

test('replaces a card when a newer child event arrives and keeps its first sequence', () => {
    const child = (sequence, state) => ({ sequence, type: 'child', createdAt: at, data: { childThreadId: 7, name: 'c', kind: 'implement', model: null, state, step: null, resultSummary: null, branch: null, messagesPaused: false } });
    const state = apply(initialEventState(), child(1, 'queued'), child(2, 'done'));

    assert.equal(state.children[7].sequence, 1);
    assert.equal(state.children[7].data.state, 'done');
    assert.equal(state.lastSequence, 2);
});

test('keeps unknown event types ignored by the reducer', () => {
    const state = apply(initialEventState(), { sequence: 1, type: 'mystery', createdAt: at, data: {} });

    assert.equal(state.lastSequence, 1);
    assert.deepEqual(state.children, {});
    assert.equal(state.entries.length, 0);
});

test('keeps an agent entry out of the transcript and tracks the agent from its events', () => {
    const agent = { id: 'a1', status: 'running', description: 'Research' };
    const state = apply(
        initialEventState(),
        user(1),
        { sequence: 2, type: 'entry', createdAt: at, data: { role: 'assistant', messageId: 'm2', agentId: 'a1', blocks: [{ type: 'text', text: 'agent step' }] } },
        { sequence: 3, type: 'agent.started', createdAt: at, data: agent },
        { sequence: 4, type: 'agent.progress', createdAt: at, data: { ...agent, lastTool: 'Bash' } },
        { sequence: 5, type: 'agent.finished', createdAt: at, data: { ...agent, lastTool: 'Bash', status: 'completed' } },
    );

    assert.equal(state.entries.length, 1);
    assert.equal(state.agents.a1.status, 'completed');
    assert.equal(state.agents.a1.lastTool, 'Bash');
    assert.equal(state.lastSequence, 5);
});

test('a block first seen mid-way stays hidden until its entry', () => {
    const state = apply(initialEventState(), delta(1, 'llo', 'm1:0', 40));

    assert.equal(state.streamingBlocks['m1:0'].seen, false);

    const done = apply(state, { ...assistant(2), data: { role: 'assistant', messageId: 'm1', blockIndex: 0, blocks: [{ type: 'text', text: 'done' }] } });

    assert.deepEqual(done.streamingBlocks, {});
    assert.equal(done.entries.length, 1);
});

test('a delta whose offset does not match marks the block unseen', () => {
    const state = apply(initialEventState(), delta(1, 'he'), delta(2, 'llo', 'm1:0', 5));

    assert.equal(state.streamingBlocks['m1:0'].seen, false);
});

test('a catch-up gap marks open blocks unseen', () => {
    const state = apply(initialEventState(), delta(1, 'he'));

    assert.equal(state.streamingBlocks['m1:0'].seen, true);
    assert.equal(apply(state, user(4)).streamingBlocks['m1:0'].seen, false);
    assert.equal(reduceEvents(state, { kind: 'events', events: [], latestSequence: 9 }).streamingBlocks['m1:0'].seen, false);
});

test('a final entry replaces a cut-off entry with the same key', () => {
    const entry = (sequence, extra, text) => ({ sequence, type: 'entry', createdAt: at, data: { role: 'assistant', messageId: 'm1', blockIndex: 0, blocks: [{ type: 'text', text }], ...extra } });
    const state = apply(initialEventState(), entry(1, { cutOff: true }, 'par'), entry(2, {}, 'partial done'));

    assert.equal(state.entries.length, 1);
    assert.equal(state.entries[0].cutOff, undefined);
});

test('block.cut flags the received entry and ignores an unknown key', () => {
    const entry = { sequence: 1, type: 'entry', createdAt: at, data: { role: 'assistant', messageId: 'm1', blockIndex: 0, blocks: [{ type: 'text', text: 'x' }] } };
    const cut = (sequence, blockKey) => ({ sequence, type: 'block.cut', createdAt: at, data: { blockKey, reason: 'stopped' } });
    const state = apply(initialEventState(), entry, cut(2, 'm1:0'), cut(3, 'm9:0'));

    assert.equal(state.entries[0].cutOff, true);
    assert.equal(state.entries[0].cutReason, 'stopped');
    assert.equal(state.entries.length, 1);
});

test("a block's entry leaves the next block of its message streaming", () => {
    const entry = { sequence: 3, type: 'entry', createdAt: at, data: { role: 'assistant', messageId: 'm1', blockIndex: 0, blocks: [{ type: 'text', text: 'a' }] } };
    const state = apply(initialEventState(), delta(1, 'a'), delta(2, 'b', 'm1:1'), entry);

    assert.deepEqual(Object.keys(state.streamingBlocks), ['m1:1']);
    assert.equal(state.streamingBlocks['m1:1'].seen, true);
});

test("an opening delta's startedAt is the block's start time", () => {
    const opening = { sequence: 1, type: 'delta', createdAt: '2026-11-11T00:00:09Z', data: { blockKey: 'm1:0', kind: 'text', text: 'a', offset: 0, startedAt: '2026-11-11T00:00:07Z' } };
    const state = apply(initialEventState(), opening, delta(2, 'b', 'm1:0', 1));

    assert.equal(state.streamingBlocks['m1:0'].startedAt, '2026-11-11T00:00:07Z');
});

test('a late delta for a block that has its final entry is ignored, and a cut-off copy never replaces the entry', () => {
    const entry = (sequence, text, extra = {}) => ({ sequence, type: 'entry', createdAt: at, data: { role: 'assistant', messageId: 'm1', blockIndex: 0, blocks: [{ type: 'text', text }], ...extra } });
    const state = apply(initialEventState(), delta(1, '- Fixed the loggin'), entry(2, '- Fixed the logging'), delta(3, 'g'), entry(4, 'g', { cutOff: true, cutReason: 'restarted' }));

    assert.deepEqual(state.streamingBlocks, {});
    assert.equal(state.entries.length, 1);
    assert.equal(state.entries[0].blocks[0].text, '- Fixed the logging');
    assert.equal(state.entries[0].cutOff, undefined);
});

test('a turn that stops running clears the streaming blocks', () => {
    const turn = (sequence, state) => ({ sequence, type: 'turn', createdAt: at, data: { turnId: 7, state } });
    const running = apply(initialEventState(), turn(1, 'running'), delta(2, 'half a sent'));

    assert.equal(apply(running, turn(3, 'running')).streamingBlocks['m1:0'].text, 'half a sent');
    assert.deepEqual(apply(running, turn(3, 'failed')).streamingBlocks, {});
});
