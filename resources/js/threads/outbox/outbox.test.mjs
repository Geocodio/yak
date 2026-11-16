import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createOutbox } from './outbox.ts';

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function harness(outcomes) {
    const posted = [];
    const timers = [];
    let keys = 0;
    const outbox = createOutbox({
        post: async (threadId, message) => {
            posted.push(`${message.key}:${message.text}`);

            return outcomes.shift() ?? { kind: 'sent' };
        },
        onDelivered: async () => {},
        newKey: () => `k${++keys}`,
        schedule: (callback) => timers.push(callback),
    });

    return { outbox, posted, runTimers: () => timers.splice(0).forEach((timer) => timer()) };
}

test('sends in order and empties the queue', async () => {
    const { outbox, posted } = harness([]);
    outbox.enqueue(1, 'first', null);
    outbox.enqueue(1, 'second', null);
    await settle();

    assert.deepEqual(posted, ['k1:first', 'k2:second']);
    assert.deepEqual(outbox.messages(1), []);
});

test('an unreachable server makes the message wait, then repeats it with the same key', async () => {
    const { outbox, posted, runTimers } = harness([{ kind: 'unreachable' }]);
    outbox.enqueue(1, 'hello', null);
    outbox.enqueue(1, 'behind', null);
    await settle();

    assert.deepEqual(outbox.messages(1).map((message) => message.status), ['waiting', 'queued']);

    runTimers();
    await settle();

    assert.deepEqual(posted, ['k1:hello', 'k1:hello', 'k2:behind']);
    assert.deepEqual(outbox.messages(1), []);
});

test('a refused message keeps its reason and holds the queue until it is retried', async () => {
    const { outbox, posted } = harness([{ kind: 'refused', reason: 'This thread is closed.' }]);
    outbox.enqueue(1, 'hello', null);
    outbox.enqueue(1, 'behind', null);
    await settle();

    assert.equal(outbox.messages(1)[0].status, 'refused');
    assert.equal(outbox.messages(1)[0].reason, 'This thread is closed.');
    assert.deepEqual(posted, ['k1:hello']);

    outbox.retry(1, 'k1');
    await settle();

    assert.deepEqual(posted, ['k1:hello', 'k1:hello', 'k2:behind']);
});

test('cancel returns the text and lets the next message go', async () => {
    const { outbox, posted } = harness([{ kind: 'refused', reason: 'no' }]);
    outbox.enqueue(1, 'hello', null);
    outbox.enqueue(1, 'behind', null);
    await settle();

    assert.equal(outbox.cancel(1, 'k1'), 'hello');
    await settle();

    assert.deepEqual(posted, ['k1:hello', 'k2:behind']);
    assert.deepEqual(outbox.messages(1), []);
});

test('queues of different threads do not hold each other', async () => {
    const { outbox, posted } = harness([{ kind: 'refused', reason: 'no' }]);
    outbox.enqueue(1, 'stuck', null);
    outbox.enqueue(2, 'free', null);
    await settle();

    assert.deepEqual(posted, ['k1:stuck', 'k2:free']);
    assert.deepEqual(outbox.messages(2), []);
});
