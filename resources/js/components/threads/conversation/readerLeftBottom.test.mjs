import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readerLeftBottom } from './readerLeftBottom.ts';

test('an upward move leaves the bottom, even a small one', () => {
    assert.equal(readerLeftBottom({ scrollTopAtInput: 500, scrollTopNow: 490, distanceFromBottom: 10 }), true);
});

test('input that moved nothing at the bottom did not leave', () => {
    assert.equal(readerLeftBottom({ scrollTopAtInput: 500, scrollTopNow: 500, distanceFromBottom: 0 }), false);
});

test('ending far from the bottom leaves', () => {
    assert.equal(readerLeftBottom({ scrollTopAtInput: 500, scrollTopNow: 500, distanceFromBottom: 200 }), true);
});
