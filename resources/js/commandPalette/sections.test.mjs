import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildSections } from './sections.ts';

const action = (id, label, extra = {}) => ({ id, label, keywords: [], ...extra });
const groups = [
    { group: 'This page', actions: [action('page', 'Show files')] },
    { group: 'Create', actions: [action('new', 'New thread', { keywords: ['create'] })] },
    { group: 'Go to', actions: [action('next', 'Next thread', { unavailable: 'Nothing needs you' }), action('repos', 'Repositories')] },
];

test('an empty query lists every available action in group order and hides unavailable ones', () => {
    const sections = buildSections(groups, '');
    assert.deepEqual(sections.map((section) => section.title), ['This page', 'Create', 'Go to']);
    assert.deepEqual(sections[2].rows.map((row) => row.action.id), ['repos']);
});

test('a typed query keeps matches only, shows unavailable matches as disabled with the reason', () => {
    const sections = buildSections(groups, 'next');
    assert.equal(sections.length, 1);
    assert.equal(sections[0].rows[0].action.id, 'next');
    assert.equal(sections[0].rows[0].disabledReason, 'Nothing needs you');
});

test('a pinned action matches on its own query and its section comes first', () => {
    const sections = buildSections(groups, 'new fix files', { id: 'new', query: 'new' });
    assert.equal(sections[0].title, 'Create');
    assert.equal(sections[0].rows[0].action.id, 'new');
});
