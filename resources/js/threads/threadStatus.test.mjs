import assert from 'node:assert/strict';
import { test } from 'node:test';
import { rebuildProgress, threadStatus } from './threadStatus.ts';

const notice = (kind) => ({ kind });

test('maps notice kinds to a label and tone', () => {
    assert.deepEqual(threadStatus(notice('restoring'), 'idle'), { label: 'Rebuilding', tone: 'info' });
    assert.deepEqual(threadStatus(notice('restore_failed'), 'idle'), { label: 'Rebuild failed', tone: 'fail' });
    assert.deepEqual(threadStatus(notice('waiting_for_account'), 'idle'), { label: 'Waiting for the account', tone: 'warn' });
    assert.deepEqual(threadStatus(notice('waiting_for_sandbox'), 'idle'), { label: 'Waiting for a sandbox', tone: 'warn' });
    for (const kind of ['account_invalid', 'account_expired', 'credential_exposure']) {
        assert.deepEqual(threadStatus(notice(kind), 'idle'), { label: 'Token problem', tone: 'fail' });
    }
    assert.deepEqual(threadStatus(notice('usage_limit'), 'idle'), { label: 'Usage limit', tone: 'fail' });
    for (const kind of ['guard_turns', 'guard_time']) {
        assert.deepEqual(threadStatus(notice(kind), 'idle'), { label: 'Stopped', tone: 'warn' });
    }
});

test('falls back to the composer state', () => {
    assert.deepEqual(threadStatus(null, 'working'), { label: 'Working', tone: 'brand' });
    assert.equal(threadStatus(null, 'idle'), null);
    assert.equal(threadStatus(null, 'readonly'), null);
});

test('a notice wins over the composer state', () => {
    assert.equal(threadStatus(notice('restoring'), 'working').label, 'Rebuilding');
});

test('rebuildProgress points at the failed step, else the first unfinished one', () => {
    const step = (status, label) => ({ status, label });
    const running = { kind: 'restoring', restoreSteps: [step('done', 'a'), step('done', 'b'), step('running', 'c'), step('pending', 'd')] };
    assert.deepEqual(rebuildProgress(running), { current: 3, total: 4, label: 'c' });
    const failed = { kind: 'restore_failed', restoreSteps: [step('done', 'a'), step('failed', 'b'), step('pending', 'c')] };
    assert.deepEqual(rebuildProgress(failed), { current: 2, total: 3, label: 'b' });
    assert.equal(rebuildProgress({ kind: 'usage_limit', restoreSteps: [] }), null);
    assert.deepEqual(rebuildProgress({ kind: 'restoring', restoreSteps: [] }), { current: 1, total: 0, label: null });
});
