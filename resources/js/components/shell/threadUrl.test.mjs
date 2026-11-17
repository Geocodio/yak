import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isThreadsSectionUrl, threadIdOfUrl } from './threadUrl.ts';

test('a thread URL gives its id, with or without a query, a hash or a sub-path', () => {
    assert.equal(threadIdOfUrl('/threads/abc12345'), 'abc12345');
    assert.equal(threadIdOfUrl('/threads/abc12345?tab=files#x'), 'abc12345');
    assert.equal(threadIdOfUrl('/threads/abc12345/diff'), 'abc12345');
});

test('reserved segments and other URLs give no id', () => {
    for (const url of ['/threads/log', '/threads/log?status[]=done', '/threads/new', '/threads/list', '/threads', '/repos', '/']) {
        assert.equal(threadIdOfUrl(url), null, url);
    }
});

test('the Threads section holds home, new and thread pages but not the thread log', () => {
    assert.equal(isThreadsSectionUrl('/'), true);
    assert.equal(isThreadsSectionUrl('/threads/new'), true);
    assert.equal(isThreadsSectionUrl('/threads/abc12345'), true);
    assert.equal(isThreadsSectionUrl('/threads/log'), false);
    assert.equal(isThreadsSectionUrl('/repos'), false);
});
