import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pullRequestToast } from './pullRequestToast.ts';

const pr = { number: 128, url: 'https://github.com/acme/app/pull/128' };

test('says the PR is open once the opening ends with a pull request', () => {
    assert.deepEqual(pullRequestToast('opening', null, pr, null), { kind: 'open', number: 128, url: pr.url });
});

test('says a co-owner must approve when the opening ends held', () => {
    assert.deepEqual(pullRequestToast('opening', 'held', null, null), { kind: 'held' });
});

test('gives the reason when the opening fails', () => {
    assert.deepEqual(pullRequestToast('opening', 'failed', null, 'GitHub said: nope'), { kind: 'failed', reason: 'GitHub said: nope' });
});

test('stays quiet while opening and for states it did not watch begin', () => {
    assert.equal(pullRequestToast('opening', 'opening', null, null), null);
    assert.equal(pullRequestToast(null, 'failed', null, 'old failure'), null);
    assert.equal(pullRequestToast(null, null, pr, null), null);
    assert.equal(pullRequestToast('failed', null, null, null), null);
});
