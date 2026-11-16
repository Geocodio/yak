import assert from 'node:assert/strict';
import { test } from 'node:test';
import { changeLetterFor } from './changeLetter.ts';

test('added paths get A, modified get M, others null', () => {
    const changed = { changedPaths: ['a.ts', 'b.ts'], addedPaths: ['b.ts'] };
    assert.equal(changeLetterFor('a.ts', changed), 'M');
    assert.equal(changeLetterFor('b.ts', changed), 'A');
    assert.equal(changeLetterFor('c.ts', changed), null);
});
