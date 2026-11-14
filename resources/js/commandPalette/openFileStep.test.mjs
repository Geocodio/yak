import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FILES_UNAVAILABLE, openFileStep } from './openFileStep.ts';

const listing = { '': [{ name: 'src', path: 'src', isDirectory: true }, { name: 'a.txt', path: 'a.txt', isDirectory: false }], src: [{ name: 'b.ts', path: 'src/b.ts', isDirectory: false }] };
const fetchDirectory = async (path) => ({ status: 200, body: { entries: listing[path] } });

test('lists matching files breadth first', async () => {
    const rows = await openFileStep(fetchDirectory, () => {}).items('b');
    assert.deepEqual(rows.map((row) => row.label), ['src/b.ts']);
});

test('a failing root listing gives one unavailable row, not an empty list', async () => {
    for (const failing of [async () => { throw new Error('asleep'); }, async () => ({ status: 503, body: {} })]) {
        const rows = await openFileStep(failing, () => {}).items('');
        assert.equal(rows.length, 1);
        assert.equal(rows[0].unavailable, FILES_UNAVAILABLE);
    }
});
