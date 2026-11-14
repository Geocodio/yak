import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { clearJsonCache, fillJsonCache, readCachedJson, writeCachedJson } from './jsonCache.ts';

beforeEach(() => clearJsonCache());

test('returns what was written for a url', () => {
    writeCachedJson('/a', { files: 2 });

    assert.deepEqual(readCachedJson('/a'), { files: 2 });
    assert.equal(readCachedJson('/b'), undefined);
});

test('filling fills the cache', async () => {
    await fillJsonCache('/a', async () => ({ ok: 1 }));

    assert.deepEqual(readCachedJson('/a'), { ok: 1 });
});

test('filling skips a url that is already cached', async () => {
    writeCachedJson('/a', { ok: 1 });
    let calls = 0;
    await fillJsonCache('/a', async () => { calls++; return { ok: 2 }; });

    assert.equal(calls, 0);
    assert.deepEqual(readCachedJson('/a'), { ok: 1 });
});

test('two fills of one url share one request', async () => {
    let calls = 0;
    const fetcher = async () => { calls++; return { ok: 1 }; };
    await Promise.all([fillJsonCache('/a', fetcher), fillJsonCache('/a', fetcher)]);

    assert.equal(calls, 1);
});

test('a failed fill leaves the cache empty and does not throw', async () => {
    await fillJsonCache('/a', async () => { throw new Error('down'); });

    assert.equal(readCachedJson('/a'), undefined);
});
