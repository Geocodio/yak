import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pullRequestResultAction, repositoryResultAction, threadResultAction } from './resultActions.ts';

const opened = [];
const environment = { threadUrl: (id) => `/threads/${id}`, openNewThread: (options) => opened.push(options) };
const thread = (extra = {}) => ({ id: 'abc', title: 'Fix login', repo: 'acme/website', branch: 'yak/x', status: 'open', previewUrl: null, pullRequestUrl: null, lastActivityAt: null, ...extra });

test('a thread result links to the thread and its more step starts with Open thread', async () => {
    const action = threadResultAction(thread(), environment);
    assert.equal(action.id, 'thread:abc');
    assert.equal(action.label, 'Fix login');
    assert.equal(action.hint, 'acme/website');
    assert.equal(action.href, '/threads/abc');
    assert.equal(action.more.title, 'Fix login');
    const items = await action.more.items('');
    assert.equal(items[0].label, 'Open thread');
    assert.equal(items[0].href, '/threads/abc');
    assert.ok(items.some((item) => item.id === 'thread-open-preview' && item.unavailable));
});

test('a thread result\'s own actions carry no hotkey', async () => {
    const items = await threadResultAction(thread({ previewUrl: 'https://p.example.com', pullRequestUrl: 'https://github.com/a/b/pull/1' }), environment).more.items('');
    assert.ok(items.some((item) => item.id === 'thread-open-preview'));
    assert.deepEqual(items.filter((item) => item.shortcut !== undefined), []);
});

test('a closed thread says so in its hint', () => {
    assert.equal(threadResultAction(thread({ status: 'closed' }), environment).hint, 'acme/website · closed');
});

test('a repository result opens its page and offers a new thread there', async () => {
    const action = repositoryResultAction({ slug: 'acme/website', name: 'website', url: '/repos/1', githubUrl: 'https://github.com/acme/website', isActive: true }, environment);
    assert.equal(action.id, 'repository:acme/website');
    assert.equal(action.hint, 'repository');
    assert.equal(action.href, '/repos/1');
    const items = await action.more.items('');
    assert.deepEqual(items.map((item) => item.label), ['New thread in this repository', 'Open on GitHub']);
    items[0].run();
    assert.deepEqual(opened, [{ repository: 'acme/website' }]);
    const without = repositoryResultAction({ slug: 's', name: 'n', url: '/r', githubUrl: null, isActive: true }, environment);
    assert.equal((await without.more.items('')).length, 1);
    const inactive = repositoryResultAction({ slug: 's', name: 'n', url: '/r', githubUrl: 'https://github.com/a/s', isActive: false }, environment);
    assert.deepEqual((await inactive.more.items('')).map((item) => item.label), ['Open on GitHub']);
});

test('a pull request result has no more step without a thread or review', async () => {
    const row = { number: 7, title: 'Add search', repo: 'acme/website', url: 'https://github.com/acme/website/pull/7', threadId: null, reviewUrl: null };
    const bare = pullRequestResultAction(row, environment);
    assert.equal(bare.id, 'pull-request:acme/website#7');
    assert.equal(bare.hint, 'acme/website #7');
    assert.equal(bare.more, undefined);
    const full = pullRequestResultAction({ ...row, threadId: 'abc', reviewUrl: '/reviews/7' }, environment);
    const items = await full.more.items('');
    assert.deepEqual(items.map((item) => [item.label, item.href]), [['Go to its thread', '/threads/abc'], ['Go to its review', '/reviews/7']]);
});
