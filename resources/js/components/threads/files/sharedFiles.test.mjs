import assert from 'node:assert/strict';
import { test } from 'node:test';
import { placeSharedFiles } from './sharedFiles.ts';

const event = (sequence) => ({ type: 'event', item: { sequence } });
const person = () => ({ type: 'person' });
const file = (id, anchorSequence) => ({ id, anchorSequence });

test('a turn\'s files sit after the last event up to their anchor', () => {
    const entries = [event(1), event(2), person(), event(5), event(6)];
    const { after, trailing } = placeSharedFiles(entries, [file('a', 2), file('b', 2), file('c', 6)]);

    assert.deepEqual(after.get(1).map((group) => group.map((f) => f.id)), [['a', 'b']]);
    assert.deepEqual(after.get(4).map((group) => group.map((f) => f.id)), [['c']]);
    assert.deepEqual(trailing, []);
});

test('files without an anchor, or before any loaded event, go at the end', () => {
    const { after, trailing } = placeSharedFiles([event(10)], [file('a', null), file('b', 3)]);

    assert.equal(after.size, 0);
    assert.deepEqual(trailing.map((f) => f.id), ['a', 'b']);
});
