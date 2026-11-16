import assert from 'node:assert/strict';
import { test } from 'node:test';
import { composerCopy } from './composerCopy.ts';

const notice = (kind) => ({ kind });
const DEFAULT = { placeholder: 'Message Claude', hint: 'Enter to send, Shift+Enter for a new line' };

test('default copy', () => {
    assert.deepEqual(composerCopy(null, 'idle', 'none'), DEFAULT);
    assert.equal(composerCopy(notice('usage_limit'), 'idle', 'none').placeholder, 'Message Claude. It reads this after the limit resets.');
});

test('working', () => {
    assert.deepEqual(composerCopy(null, 'working', 'none'), { ...DEFAULT, placeholder: 'Add a message. Claude reads it as soon as it can.' });
});

test('restoring', () => {
    assert.deepEqual(composerCopy(notice('restoring'), 'idle', 'none'), {
        placeholder: 'Message Claude. It reads this once the sandbox is back.',
        hint: 'Enter to send. Claude reads it once the sandbox is back.',
    });
});

test('waiting for the account and for a sandbox', () => {
    assert.deepEqual(composerCopy(notice('waiting_for_account'), 'idle', 'none'), {
        placeholder: 'Message Claude. It reads this when its turn starts.',
        hint: 'Enter to send. Claude reads it when its turn starts.',
    });
    assert.equal(composerCopy(notice('waiting_for_sandbox'), 'idle', 'none').hint, 'Enter to send. Claude reads it when the sandbox is up.');
    assert.equal(composerCopy(notice('waiting_for_sandbox'), 'idle', 'none').placeholder, 'Message Claude. It reads this when the sandbox is up.');
});

test('teleport hints win', () => {
    assert.equal(composerCopy(notice('restoring'), 'idle', 'waiting').hint, 'Yak keeps working until you run the command on your computer.');
    assert.match(composerCopy(null, 'disabled_computer', 'on_device').hint, /^This thread is on /);
});
