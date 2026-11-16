import assert from 'node:assert/strict';
import { test } from 'node:test';
import { checksLabel, durationLabel, moveRow } from './pullRequestsState.ts';

test('moves a row one step and stops at the ends', () => {
    assert.deepEqual(moveRow([1, 2, 3], 2, -1), [2, 1, 3]);
    assert.deepEqual(moveRow([1, 2, 3], 2, 1), [1, 3, 2]);
    assert.deepEqual(moveRow([1, 2, 3], 1, -1), [1, 2, 3]);
    assert.deepEqual(moveRow([1, 2, 3], 3, 1), [1, 2, 3]);
    assert.deepEqual(moveRow([1, 2, 3], 9, 1), [1, 2, 3]);
});

test('labels checks and durations', () => {
    assert.equal(checksLabel(2, 3), '2 of 3 checks');
    assert.equal(checksLabel(0, 0), null);
    assert.equal(durationLabel(160), '2m 40s');
    assert.equal(durationLabel(28), '28s');
    assert.equal(durationLabel(null), '');
});
