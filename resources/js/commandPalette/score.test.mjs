import assert from 'node:assert/strict';
import { test } from 'node:test';
import { rankActions, scoreAction } from './score.ts';

const candidate = (label, keywords = []) => ({ id: label, label, keywords });

test('label prefix beats keyword word prefix beats substring beats letters in order', () => {
    const ranked = rankActions('pre', [
        candidate('Compress'),
        candidate('Open app', ['preview']),
        candidate('Preview settings'),
    ]);
    assert.deepEqual(ranked.map((item) => item.label), ['Preview settings', 'Open app', 'Compress']);
    assert.ok(scoreAction('prv', { label: 'Open preview', keywords: [] }) > 0);
});

test('every query word must match', () => {
    assert.ok(scoreAction('open preview', { label: 'Open preview', keywords: [] }) > 0);
    assert.equal(scoreAction('open zebra', { label: 'Open preview', keywords: [] }), 0);
});

test('keywords match', () => {
    const newThread = { label: 'New thread', keywords: ['create', 'new', 'start', 'spawn', 'task'] };
    for (const word of ['create', 'new', 'start', 'spawn']) {
        assert.ok(scoreAction(word, newThread) > 0, word);
    }
});

test('case and surrounding spaces are ignored; empty query keeps input order', () => {
    assert.equal(scoreAction('  PREVIEW ', { label: 'Open preview', keywords: [] }), scoreAction('preview', { label: 'Open preview', keywords: [] }));
    const items = [candidate('b'), candidate('a'), candidate('c')];
    assert.deepEqual(rankActions('  ', items), items);
});

test('boost reorders within a tier but never across tiers', () => {
    const first = candidate('Preview one');
    const second = candidate('Preview two');
    assert.deepEqual(rankActions('pre', [first, second], (id) => (id === 'Preview two' ? 5 : 0)), [second, first]);

    const prefix = candidate('Preview');
    const substring = candidate('Compress');
    assert.deepEqual(rankActions('pre', [substring, prefix], (id) => (id === 'Compress' ? 100 : 0)), [prefix, substring]);
});
