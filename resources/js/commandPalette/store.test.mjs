import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { findActionByShortcut, getActionGroups, GROUP_ORDER, registerActions, subscribe, unregisterActions } from './store.ts';

const action = (id, shortcut) => ({ id, label: id, keywords: [], shortcut });
const tokens = [];
const token = () => {
    const created = Symbol('token');
    tokens.push(created);
    return created;
};

beforeEach(() => {
    for (const created of tokens.splice(0)) {
        unregisterActions(created);
    }
});

test('register then get', () => {
    registerActions(token(), 'This thread', [action('a')]);
    assert.deepEqual(getActionGroups().map((group) => [group.group, group.actions.map((item) => item.id)]), [['This thread', ['a']]]);
});

test('a second register with the same token replaces the first; unregister removes', () => {
    const first = token();
    registerActions(first, 'Page', [action('a')]);
    registerActions(first, 'Page', [action('b')]);
    assert.deepEqual(getActionGroups()[0].actions.map((item) => item.id), ['b']);
    unregisterActions(first);
    assert.deepEqual(getActionGroups(), []);
});

test('two tokens in one group merge in registration order', () => {
    registerActions(token(), 'Page', [action('a')]);
    registerActions(token(), 'Page', [action('b')]);
    const groups = getActionGroups();
    assert.equal(groups.length, 1);
    assert.deepEqual(groups[0].actions.map((item) => item.id), ['a', 'b']);
});

test('findActionByShortcut finds the action and forgets it after unregister', () => {
    const owner = token();
    registerActions(owner, 'Page', [action('a'), action('preview', 'openPreview')]);
    assert.equal(findActionByShortcut('openPreview')?.id, 'preview');
    assert.equal(findActionByShortcut('showFiles'), null);
    unregisterActions(owner);
    assert.equal(findActionByShortcut('openPreview'), null);
});

test('listeners fire on each change and stop after unsubscribe', () => {
    let calls = 0;
    const unsubscribe = subscribe(() => {
        calls += 1;
    });
    const owner = token();
    registerActions(owner, 'Page', [action('a')]);
    registerActions(owner, 'Page', [action('b')]);
    unregisterActions(owner);
    assert.equal(calls, 3);
    unsubscribe();
    registerActions(token(), 'Page', [action('c')]);
    assert.equal(calls, 3);
});

test('the snapshot is stable between changes', () => {
    registerActions(token(), 'Page', [action('a')]);
    assert.equal(getActionGroups(), getActionGroups());
});

test('groups come back in GROUP_ORDER with page groups first', () => {
    assert.deepEqual(GROUP_ORDER, ['Create', 'Go to', 'View', 'Settings', 'Help']);
    registerActions(token(), 'Help', [action('h')]);
    registerActions(token(), 'Create', [action('c')]);
    registerActions(token(), 'This thread', [action('t')]);
    registerActions(token(), 'Go to', [action('g')]);
    assert.deepEqual(getActionGroups().map((group) => group.group), ['This thread', 'Create', 'Go to', 'Help']);
});
