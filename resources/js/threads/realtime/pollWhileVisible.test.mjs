import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pollWhileVisible } from './pollWhileVisible.ts';

function harness() {
    const state = { visible: true, requests: 0, tick: null, delay: null, cleared: null };
    const options = {
        isVisible: () => state.visible,
        schedule: (callback, delay) => {
            state.tick = callback;
            state.delay = delay;

            return 7;
        },
        clear: (handle) => {
            state.cleared = handle;
        },
    };

    return { state, options };
}

test('requests on a tick while visible', () => {
    const { state, options } = harness();
    pollWhileVisible(() => state.requests++, options);

    state.tick();

    assert.equal(state.requests, 1);
});

test('does nothing on a hidden tick', () => {
    const { state, options } = harness();
    pollWhileVisible(() => state.requests++, options);

    state.visible = false;
    state.tick();

    assert.equal(state.requests, 0);
});

test('stop clears the timer', () => {
    const { state, options } = harness();
    const stop = pollWhileVisible(() => state.requests++, options);

    stop();

    assert.equal(state.cleared, 7);
});

test('default interval is 3000 ms', () => {
    const { state, options } = harness();
    pollWhileVisible(() => {}, options);

    assert.equal(state.delay, 3000);
});
