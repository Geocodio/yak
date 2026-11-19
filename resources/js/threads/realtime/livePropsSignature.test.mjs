import assert from 'node:assert/strict';
import { test } from 'node:test';
import { livePropsSignature } from './livePropsSignature.ts';

const thread = (overrides = {}) => ({ status: 'open', sandboxState: 'running', runnerState: 'idle', blockedReason: null, pullRequestOpening: null, ownerSide: 'yak', ...overrides });

test('changes when the thread moves between Yak and a computer, so the page reloads its props', () => {
    const onYak = livePropsSignature('t1', { turn: null, thread: thread() });
    const onDevice = livePropsSignature('t1', { turn: null, thread: thread({ ownerSide: 'device' }) });

    assert.notEqual(onYak, onDevice);
});

test('stays the same when nothing behind the props changed', () => {
    assert.equal(livePropsSignature('t1', { turn: null, thread: thread() }), livePropsSignature('t1', { turn: null, thread: thread() }));
});

test('changes when the cards above the composer change, so the page reloads its props', () => {
    assert.notEqual(livePropsSignature('t1', { turn: null, thread: thread({ showKey: 'a1' }) }), livePropsSignature('t1', { turn: null, thread: thread({ showKey: 'b2' }) }));
});
