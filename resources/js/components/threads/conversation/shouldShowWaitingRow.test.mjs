import assert from 'node:assert/strict';
import { test } from 'node:test';
import { shouldShowWaitingRow } from './shouldShowWaitingRow.ts';

test('shows at once when no block has streamed', () => {
    assert.equal(shouldShowWaitingRow({ wantsRow: true, lastStreamEndedAt: null, now: 100 }), true);
});

test('waits 400 ms after a streamed block ended', () => {
    assert.equal(shouldShowWaitingRow({ wantsRow: true, lastStreamEndedAt: 1000, now: 1399 }), false);
    assert.equal(shouldShowWaitingRow({ wantsRow: true, lastStreamEndedAt: 1000, now: 1400 }), true);
});

test('never shows when the row is not wanted', () => {
    assert.equal(shouldShowWaitingRow({ wantsRow: false, lastStreamEndedAt: null, now: 5000 }), false);
});
