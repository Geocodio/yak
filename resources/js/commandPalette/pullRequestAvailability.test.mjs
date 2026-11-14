import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pullRequestUnavailableReason } from './pullRequestAvailability.ts';

test('opening and held states give a reason, other states do not', () => {
    assert.equal(pullRequestUnavailableReason('opening'), 'The pull request is being opened');
    assert.equal(pullRequestUnavailableReason('held'), 'Waiting for a co-owner');
    assert.equal(pullRequestUnavailableReason(null), undefined);
    assert.equal(pullRequestUnavailableReason(undefined), undefined);
});
