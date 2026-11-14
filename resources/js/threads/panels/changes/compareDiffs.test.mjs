import assert from 'node:assert/strict';
import { test } from 'node:test';
import { compareDiffs } from './compareDiffs.ts';

const file = (path, additions, deletions) => ({ id: 1, repo: 'acme/app', path, oldPath: null, status: 'modified', additions, deletions, isBinary: false, isTooLarge: false });
const diff = (headSha, files) => ({ headSha, files });
const keyOf = (f) => f.path;

test('marks nothing on the first load', () => {
    assert.equal(compareDiffs(null, diff('b', [file('a.ts', 1, 0)]), keyOf).size, 0);
});

test('marks nothing when the head is unchanged', () => {
    assert.equal(compareDiffs(diff('a', [file('a.ts', 1, 0)]), diff('a', [file('a.ts', 9, 0), file('b.ts', 1, 0)]), keyOf).size, 0);
});

test('marks new files and files whose counts changed', () => {
    const marks = compareDiffs(
        diff('a', [file('same.ts', 1, 1), file('grew.ts', 2, 0), file('gone.ts', 1, 0)]),
        diff('b', [file('same.ts', 1, 1), file('grew.ts', 5, 0), file('fresh.ts', 3, 0)]),
        keyOf,
    );

    assert.deepEqual([...marks.entries()].sort(), [['fresh.ts', 'new'], ['grew.ts', 'updated']]);
});
