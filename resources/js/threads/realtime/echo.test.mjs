import assert from 'node:assert/strict';
import { test } from 'node:test';

const missingKeyMessage = 'Realtime disabled: VITE_REVERB_APP_KEY is empty; using HTTP catch-up polling.';

async function freshModule() {
    return import(`./echo.ts?${Math.random()}`);
}

function captureInfo(run) {
    const original = console.info;
    const calls = [];
    console.info = (...args) => calls.push(args);

    try {
        run();
    } finally {
        console.info = original;
    }

    return calls;
}

test('empty key returns null without touching window', async () => {
    const { getEcho } = await freshModule();

    assert.equal(typeof globalThis.window, 'undefined');
    assert.equal(getEcho({ VITE_REVERB_APP_KEY: '' }), null);
    assert.equal(getEcho({}), null);
});

test('whitespace key returns null', async () => {
    const { getEcho } = await freshModule();

    assert.equal(getEcho({ VITE_REVERB_APP_KEY: '   ' }), null);
});

test('logs once in DEV across repeated calls', async () => {
    const { getEcho } = await freshModule();
    const calls = captureInfo(() => {
        getEcho({ VITE_REVERB_APP_KEY: '', DEV: true });
        getEcho({ VITE_REVERB_APP_KEY: '', DEV: true });
        getEcho({ VITE_REVERB_APP_KEY: '', DEV: true });
    });

    assert.deepEqual(calls, [[missingKeyMessage]]);
});

test('does not log outside DEV', async () => {
    const { getEcho } = await freshModule();
    const calls = captureInfo(() => {
        getEcho({ VITE_REVERB_APP_KEY: '', DEV: false });
        getEcho({ VITE_REVERB_APP_KEY: '' });
    });

    assert.deepEqual(calls, []);
});
