import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fetchJsonWithRetry } from './fetchJsonWithRetry.ts';

const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const noWait = async () => {};

function scripted(results) {
    const calls = [];
    const fetcher = async (url) => {
        calls.push(url);
        const next = results.shift();
        if (next instanceof Error) {
            throw next;
        }
        return next;
    };
    return { fetcher, calls };
}

test('retries a dropped request and returns the body', async () => {
    const { fetcher, calls } = scripted([new TypeError('Failed to fetch'), json(200, { ok: 1 })]);

    assert.deepEqual(await fetchJsonWithRetry('/x', { fetcher, wait: noWait }), { ok: 1 });
    assert.equal(calls.length, 2);
});

test('retries a 5xx answer', async () => {
    const { fetcher, calls } = scripted([json(502, null), json(503, null), json(200, { ok: 2 })]);

    assert.deepEqual(await fetchJsonWithRetry('/x', { fetcher, wait: noWait }), { ok: 2 });
    assert.equal(calls.length, 3);
});

test('never retries a 4xx answer', async () => {
    const { fetcher, calls } = scripted([json(404, null), json(200, { ok: 3 })]);

    await assert.rejects(fetchJsonWithRetry('/x', { fetcher, wait: noWait }), /Request failed \(404\)/);
    assert.equal(calls.length, 1);
});

test('gives up after the last retry', async () => {
    const { fetcher, calls } = scripted([new TypeError('a'), new TypeError('b'), new TypeError('c')]);

    await assert.rejects(fetchJsonWithRetry('/x', { fetcher, wait: noWait }), /c/);
    assert.equal(calls.length, 3);
});

test('stops at once when aborted', async () => {
    const controller = new AbortController();
    controller.abort();
    const { fetcher, calls } = scripted([new DOMException('Aborted', 'AbortError'), json(200, {})]);

    await assert.rejects(fetchJsonWithRetry('/x', { fetcher, wait: noWait, signal: controller.signal }));
    assert.equal(calls.length, 1);
});
