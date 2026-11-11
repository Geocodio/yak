import assert from 'node:assert/strict';
import { test } from 'node:test';
import { threadStatus } from './threadStatus.ts';

const notice = (kind) => ({ kind });

test('maps notice kinds to a label and tone', () => {
    assert.deepEqual(threadStatus(notice('restoring'), 'idle'), { label: 'Rebuilding', tone: 'info' });
    assert.deepEqual(threadStatus(notice('restore_failed'), 'idle'), { label: 'Rebuild failed', tone: 'fail' });
    assert.deepEqual(threadStatus(notice('waiting_for_account'), 'idle'), { label: 'Waiting for the account', tone: 'warn' });
    assert.deepEqual(threadStatus(notice('waiting_for_sandbox'), 'idle'), { label: 'Waiting for a sandbox', tone: 'warn' });
    for (const kind of ['account_invalid', 'account_expired', 'usage_limit', 'credential_exposure']) {
        assert.deepEqual(threadStatus(notice(kind), 'idle'), { label: 'Token problem', tone: 'fail' });
    }
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
