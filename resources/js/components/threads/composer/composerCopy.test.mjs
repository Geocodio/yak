import assert from 'node:assert/strict';
import { test } from 'node:test';
import { channelHint, composerCopy as fullCopy } from './composerCopy.ts';

const composerCopy = (...args) => {
    const { isDefaultHint, ...copy } = fullCopy(...args);

    return copy;
};

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

test('the channel hint says where replies land', () => {
    assert.equal(channelHint({ key: 'linear', label: 'Linear' }, false), 'Replies here and in the Linear issue land in this thread.');
    assert.equal(channelHint({ key: 'github', label: 'GitHub' }, false), 'Replies here and in the GitHub issue land in this thread.');
    assert.equal(channelHint({ key: 'slack', label: 'Slack' }, false), 'Replies here and in the Slack thread land in this thread.');
    assert.equal(channelHint({ key: 'email', label: 'Email' }, false), 'Replies here and by email land in this thread.');
    assert.equal(channelHint({ key: 'dashboard', label: 'Dashboard' }, false), null);
    assert.equal(channelHint({ key: 'sentry', label: 'Sentry' }, true), 'Your first message makes you the owner.');
});

test('only the plain send hint is flagged as replaceable', () => {
    assert.equal(fullCopy(null, 'idle', 'none').isDefaultHint, true);
    assert.equal(fullCopy(notice('usage_limit'), 'idle', 'none').isDefaultHint, true);
    assert.equal(fullCopy(null, 'idle', 'waiting').isDefaultHint, false);
    assert.equal(fullCopy(notice('restoring'), 'idle', 'none').isDefaultHint, false);
});
