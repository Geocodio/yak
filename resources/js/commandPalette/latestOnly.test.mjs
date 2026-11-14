import assert from 'node:assert/strict';
import { test } from 'node:test';
import { latestOnly } from './latestOnly.ts';

test('the first of two overlapping calls resolves null when it settles last', async () => {
    const guard = latestOnly();
    let resolveFirst;
    const first = guard(new Promise((resolve) => (resolveFirst = resolve)));
    const second = guard(Promise.resolve('second'));
    assert.equal(await second, 'second');
    resolveFirst('first');
    assert.equal(await first, null);
});

test('a lone call resolves its value and a superseded rejection resolves null', async () => {
    const guard = latestOnly();
    assert.equal(await guard(Promise.resolve(1)), 1);
    let rejectFirst;
    const first = guard(new Promise((_, reject) => (rejectFirst = reject)));
    const second = guard(Promise.resolve(2));
    rejectFirst(new Error('aborted'));
    assert.equal(await first, null);
    assert.equal(await second, 2);
});

test('the newest call still rejects', async () => {
    const guard = latestOnly();
    await assert.rejects(guard(Promise.reject(new Error('boom'))), /boom/);
});
