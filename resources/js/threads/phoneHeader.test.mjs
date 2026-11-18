import assert from 'node:assert/strict';
import { test } from 'node:test';
import { failedStepReason, isProgressShownOnPhone, isStepNearFocus, phoneStatusPill, progressFocusIndex } from './phoneHeader.ts';

const base = { noticeStatus: null, isClosed: false, isLegacy: false, isHeldForCoOwner: false, isWorking: false, pullRequest: null, isStopped: false, failedStepLabel: null };

test('the pill names the pull request state', () => {
    assert.deepEqual(phoneStatusPill({ ...base, pullRequest: { number: 3105, state: 'open' } }), { label: '#3105 Open', tone: 'ok' });
    assert.equal(phoneStatusPill({ ...base, pullRequest: { number: 3105, state: 'merged' } })?.label, '#3105 Merged');
    assert.equal(phoneStatusPill({ ...base, pullRequest: { number: 3105, state: 'closed' } })?.label, '#3105 Closed');
});

test('a notice, closed and held outrank the pull request; stopped carries its reason', () => {
    const pullRequest = { number: 1, state: 'open' };
    assert.equal(phoneStatusPill({ ...base, pullRequest, noticeStatus: { label: 'Usage limit', tone: 'fail' } })?.label, 'Usage limit');
    assert.equal(phoneStatusPill({ ...base, pullRequest, isClosed: true })?.label, 'Closed');
    assert.equal(phoneStatusPill({ ...base, isLegacy: true })?.label, 'Closed');
    assert.equal(phoneStatusPill({ ...base, isHeldForCoOwner: true })?.label, 'PR held for a co-owner');
    assert.equal(phoneStatusPill({ ...base, pullRequest, isWorking: true })?.label, 'Working');
    assert.equal(phoneStatusPill({ ...base, isStopped: true, failedStepLabel: 'CI failed' })?.label, 'Stopped, CI failed');
    assert.equal(phoneStatusPill({ ...base, isStopped: true })?.label, 'Stopped');
    assert.equal(phoneStatusPill(base), null);
});

test('the failed step loses its attempt count', () => {
    assert.equal(failedStepReason('CI failed, 2 of 2'), 'CI failed');
    assert.equal(failedStepReason('CI failed 1 of 2'), 'CI failed');
    assert.equal(failedStepReason('CI failed'), 'CI failed');
    assert.equal(failedStepReason(null), null);
});

test('the phone progress row shows while working or stopped only', () => {
    assert.deepEqual(['working', 'stopped', 'done', 'closed', 'needs_you', 'waiting_for_co_owner'].map(isProgressShownOnPhone), [true, true, false, false, false, false]);
});

test('the window shows two steps before the focus and one after', () => {
    const visible = (states) => {
        const focus = progressFocusIndex(states);
        return states.map((_, index) => isStepNearFocus(index, focus));
    };
    const seven = (focusState, at) => Array.from({ length: 7 }, (_, index) => (index < at ? 'done' : index === at ? focusState : 'upcoming'));

    assert.deepEqual(visible(seven('current', 0)), [true, true, false, false, false, false, false]);
    assert.deepEqual(visible(seven('current', 3)), [false, true, true, true, true, false, false]);
    assert.deepEqual(visible(seven('current', 6)), [false, false, false, false, true, true, true]);
    assert.deepEqual(visible(seven('failed', 3)), [false, true, true, true, true, false, false]);
});
