import assert from 'node:assert/strict';
import { test } from 'node:test';
import { threadDataActions } from './threadDataActions.ts';

const thread = (extra = {}) => ({ id: 'abc', branch: 'yak/work', previewUrl: 'https://p.example.com', pullRequestUrl: 'https://github.com/a/b/pull/1', ...extra });
const find = (actions, id) => actions.find((action) => action.id === id);

test('a thread with a preview and a pull request has runnable open actions bound to their shortcuts', () => {
    const actions = threadDataActions(thread());
    const preview = find(actions, 'thread-open-preview');
    assert.equal(preview.shortcut, 'openPreview');
    assert.equal(preview.unavailable, undefined);
    assert.equal(typeof preview.run, 'function');
    const pullRequest = find(actions, 'thread-open-pull-request');
    assert.equal(pullRequest.shortcut, 'openPullRequest');
    assert.equal(pullRequest.unavailable, undefined);
    assert.ok(find(actions, 'thread-copy-link'));
});

test('no preview url makes Open preview unavailable with a reason', () => {
    const preview = find(threadDataActions(thread({ previewUrl: null })), 'thread-open-preview');
    assert.equal(preview.unavailable, 'This thread has no preview yet');
});

test('no pull request makes Open pull request unavailable with a reason', () => {
    const pullRequest = find(threadDataActions(thread({ pullRequestUrl: null })), 'thread-open-pull-request');
    assert.equal(pullRequest.unavailable, 'This thread has no pull request yet');
});

test('no branch leaves out Copy branch name', () => {
    assert.equal(find(threadDataActions(thread({ branch: null })), 'thread-copy-branch'), undefined);
    assert.ok(find(threadDataActions(thread()), 'thread-copy-branch'));
});

test('two targets with a url give a step of targets, one target gives a plain run', async () => {
    const targets = [
        { title: 'Web', url: 'https://web.example.com' },
        { title: 'Docs', url: 'https://docs.example.com' },
        { title: 'Idle', url: null },
    ];
    const stepped = find(threadDataActions(thread({ previewTargets: targets })), 'thread-open-preview');
    assert.equal(stepped.run, undefined);
    const items = await stepped.steps.items('');
    assert.deepEqual(items.map((item) => item.label), ['Web', 'Docs']);
    assert.equal(typeof items[0].run, 'function');

    const single = find(threadDataActions(thread({ previewTargets: targets.slice(0, 1) })), 'thread-open-preview');
    assert.equal(single.steps, undefined);
    assert.equal(typeof single.run, 'function');
});
