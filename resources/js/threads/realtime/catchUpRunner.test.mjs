import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createCatchUpRunner } from './catchUpRunner.ts';

function setup(fetchAfter) {
    let last = 0;
    const statuses = [];
    const calls = [];
    const runner = createCatchUpRunner({
        fetchAfter: (after) => {
            calls.push(after);

            return fetchAfter(after);
        },
        getLastSequence: () => last,
        onResult: ({ latestSequence }) => {
            last = latestSequence;
        },
        onStatus: (status) => statuses.push(status),
    });

    return { runner, calls, statuses };
}

const gate = () => {
    let release;
    const promise = new Promise((resolve) => (release = resolve));

    return { promise, release };
};

test('reruns once when a gap is requested during an in-flight catch-up', async () => {
    const gates = [gate(), gate()];
    let run = 0;
    const { runner, calls, statuses } = setup(() => {
        const current = run++;

        return gates[current].promise.then(() => ({ events: [], latestSequence: (current + 1) * 10 }));
    });

    const first = runner.request();
    runner.request();
    gates[0].release();
    await new Promise((resolve) => setImmediate(resolve));
    gates[1].release();
    await first;

    assert.deepEqual(calls, [0, 10]);
    assert.equal(statuses.at(-1), 'live');
});

test('a request with no gap during flight causes no extra run', async () => {
    const g = gate();
    const { runner, calls } = setup(() => g.promise.then(() => ({ events: [], latestSequence: 5 })));

    const first = runner.request();
    g.release();
    await first;
    await runner.request();

    assert.deepEqual(calls, [0, 5]);
});

test('a failed fetch goes offline and does not rerun', async () => {
    const { runner, calls, statuses } = setup(() => Promise.reject(new Error('down')));

    const first = runner.request();
    runner.request();
    await first;

    assert.equal(calls.length, 1);
    assert.equal(statuses.at(-1), 'offline');
});
